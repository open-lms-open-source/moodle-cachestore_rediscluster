<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * RedisCluster session handler
 *
 * @package    cachestore_rediscluster
 * @copyright  2017 Blackboard Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace cachestore_rediscluster;

defined('MOODLE_INTERNAL') || die();

/**
 * RedisCluster Session handler
 *
 * Forked from the core redis session handler.
 */
class session extends \core\session\handler {

    /**
     * The connection config for RedisCluster.
     *
     * @var string
     */
    protected $config;

    /**
     * How long to wait for a session lock (in seconds).
     *
     * @var int
     */
    protected $acquiretimeout = 120;

    /**
     * How long to wait in seconds before expiring the lock automatically so
     * that other requests may continue execution.
     *
     * @var int
     */
    protected $lockexpire;

    /**
     * This key is used to track which locks a given host currently has held.
     *
     * @var string
     */
    protected $lockhostkey;

    /**
     * The RedisCluster cachestore object.
     *
     * @var cachestore_rediscluster
     */
    protected $connection = null;

    /**
     * List of currently held locks by this page.
     *
     * @var array
     */
    protected $locks = array();

    /**
     * How long sessions live before expiring from inactivity (in seconds).
     *
     * @var int
     */
    protected $timeout;

    /**
     * If enabled, this script won't attempt to get a session lock before
     * running. Useful for endpoints that are known to not affect session data.
     *
     * @var bool
     */
    protected $nolock = false;

    /**
     * The maximum amount of threads a given session can have queued waiting for
     * the session lock.
     *
     * @var int
     */
    protected $maxwaiters = 10;

    /**
     * Flag that indicates if we're currently waiting for a lock or not.
     *
     * @var bool
     */
    protected $waiting = false;

    /**
     * Create new instance of handler.
     */
    public function __construct() {
        global $CFG;

        $this->config = [
            'failover' => \RedisCluster::FAILOVER_NONE,
            'persist' => false,
            'prefix' => '',
            'readtimeout' => 3.0,
            'serializer' => \Redis::SERIALIZER_PHP,
            'server' => null,
            'serversecondary' => null,
            'session' => true,
            'timeout' => 3.0,
        ];

        foreach (array_keys($this->config) as $key) {
            if (!empty($CFG->session_rediscluster[$key])) {
                $this->config[$key] = $CFG->session_rediscluster[$key];
            }
        }

        if (isset($CFG->session_rediscluster['acquire_lock_timeout'])) {
            $this->acquiretimeout = (int)$CFG->session_rediscluster['acquire_lock_timeout'];
        }

        if (isset($CFG->session_rediscluster['max_waiters'])) {
            $this->maxwaiters = (int)$CFG->session_rediscluster['max_waiters'];
        }

        $this->lockexpire = $CFG->sessiontimeout;
        if (isset($CFG->session_rediscluster['lock_expire'])) {
            $this->lockexpire = (int)$CFG->session_rediscluster['lock_expire'];
        }

        // The following configures the session lifetime in redis to allow some
        // wriggle room in the user noticing they've been booted off and
        // letting them log back in before they lose their session entirely.
        $updatefreq = empty($CFG->session_update_timemodified_frequency) ? 20 : $CFG->session_update_timemodified_frequency;
        $this->timeout = $CFG->sessiontimeout + $updatefreq + MINSECS;

        if (!defined('NO_SESSION_LOCK')) {
            define('NO_SESSION_LOCK', false);
        }
        $this->nolock = NO_SESSION_LOCK;

        $this->lockhostkey = "mdl_locklist:".gethostname();
    }

    /**
     * Init session handler.
     */
    public function init() {
        global $CFG;

        require_once("{$CFG->dirroot}/cache/stores/rediscluster/lib.php");

        if (!extension_loaded('redis')) {
            throw new \moodle_exception('sessionhandlerproblem', 'error', '', null, 'redis extension is not loaded');
        }

        if (empty($this->config['server'])) {
            throw new \moodle_exception('sessionhandlerproblem', 'error', '', null,
                    '$CFG->session_redis[\'server\'] must be specified in config.php');
        }

        // The session handler requires a version of Redis extension that supports cluster (>= 2.2.8).
        if (!class_exists('RedisCluster')) {
            throw new \moodle_exception('sessionhandlerproblem', 'error', '',null,
                'redis extension version must be at least 2.2.8');
        }

        $this->connection = new \cachestore_rediscluster(null, $this->config);

        $result = session_set_save_handler(array($this, 'handler_open'),
            array($this, 'handler_close'),
            array($this, 'handler_read'),
            array($this, 'handler_write'),
            array($this, 'handler_destroy'),
            array($this, 'handler_gc'));
        if (!$result) {
            throw new \Exception('Session handler is misconfigured');
        }
        return true;
    }

    /**
     * Update our session search path to include session name when opened.
     *
     * @param string $savepath  unused session save path. (ignored)
     * @param string $sessionname Session name for this session. (ignored)
     * @return bool true always as we will succeed.
     */
    public function handler_open($savepath, $sessionname) {
        return true;
    }

    /**
     * Close the session completely. We also remove all locks we may have obtained that aren't expired.
     *
     * @return bool true on success.  false on unable to unlock sessions.
     */
    public function handler_close() {
        try {
            foreach ($this->locks as $id => $expirytime) {
                if ($expirytime > time()) {
                    $this->unlock_session($id);
                }
                unset($this->locks[$id]);
            }
        } catch (\RedisException $e) {
            error_log('Failed talking to redis: '.$e->getMessage());
            return false;
        }

        return true;
    }
    /**
     * Read the session data from storage
     *
     * @param string $id The session id to read from storage.
     * @return string The session data for PHP to process.
     *
     * @throws RedisException when we are unable to talk to the Redis server.
     */
    public function handler_read($id) {
        try {
            $this->lock_session($id);
            $sessiondata = $this->connection->command('get', $id);
            if ($sessiondata === false) {
                $this->unlock_session($id);
                return '';
            }
            $this->connection->command('expire', $id, $this->timeout);
        } catch (\RedisException $e) {
            error_log('Failed talking to redis: '.$e->getMessage());
            throw $e;
        }
        return $sessiondata;
    }

    /**
     * Write the serialized session data to our session store.
     *
     * @param string $id session id to write.
     * @param string $data session data
     * @return bool true on write success, false on failure
     */
    public function handler_write($id, $data) {
        if ($this->nolock) {
            return true;
        }
        if (is_null($this->connection)) {
            // The session has already been closed, don't attempt another write.
            error_log('Tried to write session: '.$id.' before open or after close.');
            return false;
        }

        // We do not do locking here because memcached doesn't.  Also
        // PHP does open, read, destroy, write, close. When a session doesn't exist.
        // There can be race conditions on new sessions racing each other but we can
        // address that in the future.
        try {
            $this->connection->command('setex', $id, $this->timeout, $data);
        } catch (\RedisException $e) {
            error_log('Failed talking to redis: '.$e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Handle destroying a session.
     *
     * @param string $id the session id to destroy.
     * @return bool true if the session was deleted, false otherwise.
     */
    public function handler_destroy($id) {
        try {
            $this->connection->command('del', $id);
            $this->unlock_session($id);
            $this->connection->command('del', $id.'.lock.waiting');
        } catch (\RedisException $e) {
            error_log('Failed talking to redis: '.$e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Garbage collect sessions.  We don't we any as Redis does it for us.
     *
     * @param integer $maxlifetime All sessions older than this should be removed.
     * @return bool true, as Redis handles expiry for us.
     */
    public function handler_gc($maxlifetime) {
        return true;
    }

    /**
     * Unlock a session.
     *
     * @param string $id Session id to be unlocked.
     */
    protected function unlock_session($id) {
        if (isset($this->locks[$id])) {
            $lockkey = "{$id}.lock";
            $this->connection->set_retry_limit(1); // Try extra hard to unlock the session.
            $this->connection->command('del', $lockkey);

            // Remove the lock from our list of held locks for this host.
            $this->connection->command_raw('hdel', $this->lockhostkey, "{$this->config['prefix']}{$lockkey}");

            unset($this->locks[$id]);
        }
    }

    /**
     * Obtain a session lock so we are the only one using it at the moent.
     *
     * @param string $id The session id to lock.
     * @return bool true when session was locked, exception otherwise.
     * @throws exception When we are unable to obtain a session lock.
     */
    protected function lock_session($id) {
        $lockkey = $id.".lock";

        if ($this->nolock) {
            return true;
        }

        $haslock = isset($this->locks[$id]) && time() < $this->locks[$id];
        $startlocktime = time();
        $waitkey = "{$lockkey}.waiting";

        // Create the waiting key, or increment it if we end up queued.
        $waitpos = $this->increment($waitkey, $this->lockexpire);
        $this->waiting = true;

        if ($waitpos > $this->maxwaiters) {
            $this->decrement($waitkey);
            $this->waiting = false;
            $this->error('sessionwaiterr');
        }

        // Ensure on timeout or exception that we try to decrement the waiter count.
        \core_shutdown_manager::register_function([$this, 'release_waiter'], [$waitkey]);

        /**
         * To be able to ensure sessions don't write out of order we must obtain an exclusive lock
         * on the session for the entire time it is open.  If another AJAX call, or page is using
         * the session then we just wait until it finishes before we can open the session.
         */
        while (!$haslock) {
            $expiry = time() + $this->lockexpire;
            $haslock = $this->get_lock($lockkey);
            if ($haslock) {
                $this->locks[$id] = $expiry;
                break;
            }

            usleep(rand(100000, 1000000));
            if (time() > $startlocktime + $this->acquiretimeout) {
                // This is a fatal error, better inform users.
                // It should not happen very often - all pages that need long time to execute
                // should close session immediately after access control checks.
                error_log('Cannot obtain session lock for sid: '.$id.' within '.$this->acquiretimeout.
                        '. It is likely another page has a long session lock, or the session lock was never released.');
                break;
            }
        }

        $this->decrement($waitkey);
        $this->waiting = false;

        if (!$haslock) {
            $this->error('sessionwaiterr');
        }
        return true;
    }

    public function release_waiter($waitkey) {
        if ($this->waiting) {
            error_log("REDIS SESSION: Still waiting [key=$waitkey] during request shutdown!");
            try {
                $this->decrement($waitkey);
            } catch (\Exception $e) {}
        }
    }

    /**
     * Get a lock, with some metadata embedded in it.
     *
     * @param $lockkey The lock key.
     *
     * @return bool Did we get a new lock for the provided lockkey.
     */
    protected function get_lock($lockkey) {
        global $CFG;

        $meta = [
            'createdat' => time(),
            'instance' => isset($CFG->puppet_instance) ? $CFG->puppet_instance : '',
            'lockkey' => $lockkey,
            'script' => isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '',
        ];

        // Try to get the lock itself.
        $haslock = $this->connection->command('set', $lockkey, json_encode($meta), ['NX', 'EX' => $this->lockexpire]);
        if ($haslock) {
            // Great, lets add it to the list of held locks for this host.
            $fulllockkey = "{$this->config['prefix']}{$lockkey}";
            $this->connection->command_raw('hset', $this->lockhostkey, $fulllockkey, $this->lockexpire);
        }
        return $haslock;
    }

    protected function error($error = 'sessionhandlerproblem') {
        if (!defined('NO_MOODLE_COOKIES')) {
            define('NO_MOODLE_COOKIES', true);
        }
        throw new \Exception($error);
    }

    protected function decrement($k) {
        $v = $this->connection->command('decr', $k);
        if ($v !== false) {
            return $v;
        }
        return 0;
    }


    protected function increment($k, $ttl) {
        // Ensure key is created with ttl before proceeding.
        if (!$this->connection->command('exists', $k)) {
            // We don't want to potentially lose the expiry, so do it in a transaction.
            $this->connection->command('multi');
            $this->connection->command('incr', $k);
            $this->connection->command('expire', $k, $this->lockexpire);
            $this->connection->command('exec');
            return 0;
        }

        // Use simple form of increment as we cannot use binary protocol.
        $v = $this->connection->command('incr', $k);
        if ($v !== false) {
            return $v;
        }

        throw new \Exception('sessionhandlerproblem', 'error', '', null,
                    'Unable to get a session waiter.');
    }

    /**
     * Check the backend contains data for this session id.
     *
     * Note: this is intended to be called from manager::session_exists() only.
     *
     * @param string $sid
     * @return bool true if session found.
     */
    public function session_exists($sid) {
        if (!$this->connection) {
            return false;
        }

        try {
            return $this->connection->command('exists', $sid);
        } catch (\RedisException $e) {
            return false;
        }
    }

    /**
     * Kill all active sessions, the core sessions table is purged afterwards.
     */
    public function kill_all_sessions() {
        global $DB;
        if (!$this->connection) {
            return;
        }

        $rs = $DB->get_recordset('sessions', array(), 'id DESC', 'id, sid');
        foreach ($rs as $record) {
            $this->handler_destroy($record->sid);
        }
        $rs->close();
    }

    /**
     * Kill one session, the session record is removed afterwards.
     *
     * @param string $sid
     */
    public function kill_session($sid) {
        if (!$this->connection) {
            return;
        }

        $this->handler_destroy($sid);
    }

    // The following functions exist to facilitate unit tests and will not do
    // anything outside of phpunit.

    public function cleanup_test_instance() {
        if (!PHPUNIT_TEST) {
            return;
        }

        if (!\cachestore_rediscluster::are_requirements_met() || empty($this->connection)) {
            return false;
        }

        $list = $this->connection->command('keys', 'phpunit*');
        foreach ($list as $keyname) {
            $this->connection->command('del', $keyname);
        }
        $this->connection->close();
    }

    public function test_find_keys($search) {
        if (!PHPUNIT_TEST) {
            return;
        }
        return $this->connection->command('keys', "{$this->config['prefix']}{$search}");
    }

}
