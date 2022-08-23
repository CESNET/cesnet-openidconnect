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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class UnlinkExternalGroup extends Command
{
	/**
	 * @var GroupMapper
	 */
	private $groupMapper;

	/**
	 * @param GroupMapper $groupMapper
	 */
	public function __construct(GroupMapper $groupMapper)
	{
		$this->groupMapper = $groupMapper;
		parent::__construct();
	}

	protected function configure()
	{
		$this
			->setName('cesnet:unlink-external-group')
			->setDescription('Unlink an external (Perun) group from an ownCloud group.')
			->addArgument(
				'externalUUID',
				InputArgument::REQUIRED,
				'Persistent UUID identifier of an external (Perun) group'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		try {
			$euuid = $input->getArgument('externalUUID');
			$group = $this->groupMapper->get($euuid);
			if ($group) {
				$target = $group->getOcGroupId();
				$this->groupMapper->delete($group);
				$output->writeln("Successfully unlinked $euuid from $target");
			}
		} catch (\Exception $e) {
			$output->writeln('<error>' . $e->getMessage(). '</error>');
		}
	}
}