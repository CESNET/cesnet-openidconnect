<?php
/**
 * @author Miroslav Bauer <bauer@cesnet.cz>
 *
 * @copyright Copyright (c) 2020, ownCloud GmbH
 * @license GPL-2.0
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\CesnetOpenIdConnect\Command;

use OCA\CesnetOpenIdConnect\Db\GroupMapper;
use OCP\IGroupManager;


class LinkExternalGroup extends Command
{
	/**
	 * @var GroupMapper
	 */
	private $groupMapper;

	/**
	 * @var IGroupManager
	 */
	private $groupManager;

	public function __construct($groupMapper, $groupManager)
	{
		$this->groupMapper = $groupMapper;
		$this->groupManager = $groupManager;
		parent::__construct();
	}

	protected function configure()
	{
		$this
			->setName('cesnet:link-external-group')
			->setDescription('Link an external (Perun) group to an ownCloud group.')
			->addArgument(
				'externalUUID',
				InputArgument::REQUIRED,
				'Persistent UUID identifier of an external (Perun) group'
			)
			->addArgument(
				'owncloudGroup',
				InputArgument::REQUIRED,
				'target ownCloud group (display) name'
			)
			->addOption(
				'createMissing',
				null,
				InputOption::VALUE_NONE,
				'create the target group if missing'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		try {
			$euuid = $input->getArgument('externalUUID');
			$target = $input->getArgument('owncloudGroup');
			$createMissing = $input->getOption('createMissing');

			if (!$this->groupManager->groupExists($target)) {
				if ($createMissing) {
					$this->groupMapper->createGroup($target);
				} else {
					throw new \Exception('Target ownCloud group does not exist. Use --create-missing.');
				}
			}

			$this->groupMapper->addGroupMapping($euuid, $target);
			$output->writeln("Successfully linked $euuid to $target");
		} catch (\Exception $e) {
			$output->writeln('<error>' . $e->getMessage(). '</error>');
		}
	}
}