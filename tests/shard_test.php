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
 * RedisCluster shard test.
 *
 * @package   cachestore_rediscluster
 * @copyright Copyright (c) 2017 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../../../tests/fixtures/stores.php');
require_once(__DIR__.'/../lib.php');
require_once(__DIR__.'/rediscluster_test.php');

/**
 * RedisCluster cache test.
 *
 * @package   cachestore_rediscluster
 * @copyright Copyright (c) 2015 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cachestore_rediscluster_shard_test extends cachestore_rediscluster_test {

    /**
     * @return cachestore_rediscluster
     */
    protected function create_cachestore_rediscluster() {
        /** @var cache_definition $definition */
        $definition = cache_definition::load_adhoc(cache_store::MODE_APPLICATION, 'cachestore_rediscluster', 'phpunit_shard_test');
        $store      = cachestore_rediscluster::initialise_unit_test_instance($definition);

        $this->store = $store;

        if (!$store) {
            $this->markTestSkipped();
        }

        return $store;
    }
}
