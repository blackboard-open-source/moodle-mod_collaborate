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
 * Unit tests for the recording_count_helper class.
 *
 * @package    mod_collaborate
 * @author     Sam Chaffee
 * @copyright  Copyright (c) 2016 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

use mod_collaborate\recording_count_helper;

require_once(__DIR__ . '/fixtures/recordingstub.php');

/**
 * Unit tests for the recording_count_helper class.
 *
 * @package    mod_collaborate
 * @copyright  Copyright (c) 2016 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_collaborate_recording_count_helper_testcase extends advanced_testcase {
    public function setUp() {
        $this->resetAfterTest();
    }
    
    public function test_get_recording_counts_legacy_store() {
        $logmanager = get_log_manager(true);
        $reader = new logstore_legacy\log\store($logmanager);

        $cminfo = (object) ['id' => 1, 'course' => 1];
        $recordings = [
            new mod_collaborate_recordingstub(1),
            new mod_collaborate_recordingstub(2),
        ];
        $cache = cache::make('mod_collaborate', 'recordingcounts');
        $recordinghelper = new recording_count_helper($cminfo, $recordings, $reader, $cache);
        $counts = $recordinghelper->get_recording_counts();
        $this->assert_empty_counts($counts);

        // Simulate firing view and download events by loading data and deleting cache.
        $data = include(__DIR__.'/fixtures/legacylogs.php');
        $this->loadDataSet($this->createArrayDataSet($data));
        $cache->delete($cminfo->id);

        $counts = $recordinghelper->get_recording_counts();
        $this->assert_counts($counts);
    }

    public function test_get_recording_counts_standard_store() {
        $logmanager = get_log_manager(true);
        $reader = new logstore_standard\log\store($logmanager);

        $cminfo = (object) ['id' => 1, 'course' => 1];
        $recordings = [
            new mod_collaborate_recordingstub(1),
            new mod_collaborate_recordingstub(2),
        ];
        $cache = cache::make('mod_collaborate', 'recordingcounts');
        $recordinghelper = new recording_count_helper($cminfo, $recordings, $reader, $cache);
        $counts = $recordinghelper->get_recording_counts();
        $this->assert_empty_counts($counts);

        // Simulate firing view and download events by loading data and deleting cache.
        $data = include(__DIR__.'/fixtures/standardlogs.php');
        $this->loadDataSet($this->createArrayDataSet($data));
        $cache->delete($cminfo->id);

        $counts = $recordinghelper->get_recording_counts();
        $this->assert_counts($counts);
    }

    /**
     * @param array $counts
     */
    protected function assert_counts($counts) {
        $this->assertCount(2, $counts);
        $this->assertArrayHasKey(1, $counts);
        $recording1 = $counts[1];
        $this->assertInstanceOf('mod_collaborate\\renderables\\recording_counts', $recording1);
        $this->assertEquals(2, $recording1->views);
        $this->assertEquals(2, $recording1->downloads);

        $this->assertArrayHasKey(2, $counts);
        $recording2 = $counts[2];
        $this->assertInstanceOf('mod_collaborate\\renderables\\recording_counts', $recording2);
        $this->assertEquals(0, $recording2->views);
        $this->assertEquals(1, $recording2->downloads);
    }

    /**
     * @param $counts
     */
    protected function assert_empty_counts($counts) {
        $this->assertCount(2, $counts);
        foreach ($counts as $count) {
            $this->assertInstanceOf('mod_collaborate\\renderables\\recording_counts', $count);
            $this->assertEquals(0, $count->views);
            $this->assertEquals(0, $count->downloads);
        }
    }
}