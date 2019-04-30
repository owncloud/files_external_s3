<?php
/**
 * @author Joas Schilling <coding@schilljs.com>
 * @author Michael Gapczynski <GapczynskiM@gmail.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files_External\Tests\Storage;

use OCA\FilesExternalS3\Storage\AmazonS3;

/**
 * Class Amazons3Test
 *
 * @group DB
 *
 * @package OCA\Files_External\Tests\Storage
 */
class Amazons3Test extends \Test\Files\Storage\Storage {

	private $config;

	protected function setUp() {
		parent::setUp();

		$this->config = include __DIR__.'/config.php';
		if ( ! is_array($this->config) or ! $this->config['run']) {
			$this->markTestSkipped('AmazonS3 backend not configured');
		}
		$this->instance = new AmazonS3($this->config);
	}

	protected function tearDown() {
		if ($this->instance) {
			$this->instance->rmdir('');
		}

		parent::tearDown();
	}

	/**
	 * @dataProvider directoryProvider
	 */
	public function testDirectories($directory) {
		$this->markTestSkipped('S3 doesn\'t handle directories correctly - see issue 7');
	}

	public function testRecursiveRmdir() {
		$this->markTestSkipped('S3 doesn\'t handle directories correctly - see issue 7');
	}

	public function testRmdirEmptyFolder() {
		$this->markTestSkipped('S3 doesn\'t handle directories correctly - see issue 7');
	}

	public function testRecursiveUnlink() {
		$this->markTestSkipped('S3 doesn\'t handle directories correctly - see issue 7');
	}

	public function testRenameDirectory() {
		$this->markTestSkipped('S3 doesn\'t handle directories correctly - see issue 7');
	}

	public function testRenameOverWriteDirectory() {
		$this->markTestSkipped('S3 doesn\'t handle directories correctly - see issue 7');
	}

	public function testRenameOverWriteDirectoryOverFile() {
		$this->markTestSkipped('S3 doesn\'t handle directories correctly - see issue 7');
	}

	public function testCopyDirectory() {
		$this->markTestSkipped('S3 doesn\'t handle directories correctly - see issue 7');
	}

	public function testCopyOverWriteDirectory() {
		$this->markTestSkipped('S3 doesn\'t handle directories correctly - see issue 7');
	}

	public function testStat() {
		$this->markTestSkipped('S3 doesn\'t update the parents folder mtime');
	}
}
