<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2016 Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FilesDownloadActivity\Activity;

use OC\Files\Filesystem;
use OCA\FilesDownloadActivity\CurrentUser;
use OCP\Activity\IManager;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IURLGenerator;

class Listener
{
	/** @var IRequest */
	protected $request;
	/** @var IManager */
	protected $activityManager;
	/** @var IURLGenerator */
	protected $urlGenerator;
	/** @var IRootFolder */
	protected $rootFolder;
	/** @var CurrentUser */
	protected $currentUser;
	/** @var ILogger */
	protected $logger;

	// XXX CDSP change -- start
	public const APP_PLUGIN_NAME = 'files_downloadactivity';
	public const FILETYPE_FOLDER_DOWNLOADED = 'folder_downloaded';
	public const FILETYPE_FILE_DOWNLOADED = 'file_downloaded';
	public const FILETYPE_FILE_PREVIEWED = 'file_previewed';
	public const FILETYPE_FILE_CREATED = 'file_created';
	public const FILETYPE_ICON_DOWNLOADED = 'file_icon_downloaded';
	public const FILETYPE_OTHER_DOWNLOADED = 'other_downloaded';
	// XXX CDSP change -- end

	/**
	 * @param IRequest $request
	 * @param IManager $activityManager
	 * @param IURLGenerator $urlGenerator
	 * @param IRootFolder $rootFolder
	 * @param CurrentUser $currentUser
	 * @param ILogger $logger
	 */
	public function __construct(IRequest $request, IManager $activityManager, IURLGenerator $urlGenerator, IRootFolder $rootFolder, CurrentUser $currentUser, ILogger $logger)
	{
		$this->request = $request;
		$this->activityManager = $activityManager;
		$this->urlGenerator = $urlGenerator;
		$this->rootFolder = $rootFolder;
		$this->currentUser = $currentUser;
		$this->logger = $logger;
	}

	/**
	 * Store the update hook events
	 * @param string $path Path of the file that has been read
	 */
	public function readFile(string $path): void
	{
		// Do not add activities for .part-files
		if (substr($path, -5) === '.part') {
			return;
		}

		if ($this->currentUser->getUID() === null) {
			// User is not logged in, this download is handled by the files_sharing app
			// XXX CDSP change
			$this->logger->info("Listener.php:readFile()...User is not logged in, this download is handled by the files_sharing app");
			return;
		}

		try {
			[$filePath, $owner, $fileId, $isDir] = $this->getSourcePathAndOwner($path);
		} catch (NotFoundException $e) {
			// XXX CDSP change logging
			$this->logger->logException($e, [
				'app' => 'files_downloadactivity',
			]);
			return;
		} catch (InvalidPathException $e) {
			// XXX CDSP change logging
			$this->logger->logException($e, [
				'app' => 'files_downloadactivity',
			]);
			return;
		}

		// XXX CDSP -- determine whether the file downloaded was performed by the owner or another user 
		if ($owner === null) {
			// is this check necessary? or perhaps useful some other way? 
			$this->logger->info("Listener.php:readFile()...no owner for found for file/folder.");

			return;
		}
		// XXX CDSP 
		$ownerDownloaded = ($this->currentUser->getUID() === $owner ? true : false);

		$user_agent_client = 'web';
		if ($this->request->isUserAgent([IRequest::USER_AGENT_CLIENT_DESKTOP])) {
			$user_agent_client = 'desktop';
		} elseif ($this->request->isUserAgent([IRequest::USER_AGENT_CLIENT_ANDROID, IRequest::USER_AGENT_CLIENT_IOS])) {
			$user_agent_client = 'mobile';
		}
		$subjectParams = [[$fileId => $filePath], $this->currentUser->getUserIdentifier(), $user_agent_client];

		if ($isDir) {
			$subject = Provider::SUBJECT_SHARED_FOLDER;
			if ($ownerDownloaded) {
				$subject = Provider::SUBJECT_FOLDER_SELF;
			}
			$linkData = [
				'dir' => $filePath,
			];
		} else {
			$parentDir = (substr_count($filePath, '/') === 1) ? '/' : dirname($filePath);
			$fileName = basename($filePath);
			$linkData = [
				'dir' => $parentDir,
				'scrollto' => $fileName,
			];
			if (isset($this->request['downloadStartSecret']) && $this->request['downloadStartSecret'] != '') {
				// condition to handle when the user initiated a file downloaded
				$fileType = self::FILETYPE_FILE_DOWNLOADED;
				$subject = Provider::SUBJECT_SHARED_FILE;
				if ($ownerDownloaded) {
					$subject = Provider::SUBJECT_FILE_SELF;
				}
			} else {
				// exit if event triggered by the owner as the following condiitons are handled by another app (filesharing?)
				if ($ownerDownloaded) {
					return;
				}
				// get the file name or path endpoint from the request URL
				$pathinfo = $this->request->getPathInfo();
				$path_filename = pathinfo($pathinfo, PATHINFO_FILENAME);
				if (isset($this->request['x']) && isset($this->request['y'])) {
					// assumption is that the file share was previewed and only the file icon was downloaded
					$fileType = self::FILETYPE_ICON_DOWNLOADED;
					$subject = Provider::SUBJECT_SHARED_FILE_ICON;
				} else if (str_contains(strtolower($path_filename), 'preview')) {
					// assumption is that the file share was previewed and was not just an file icon
					$fileType = self::FILETYPE_FILE_PREVIEWED;
					$subject = Provider::SUBJECT_SHARED_FILE_PREVIEW;
				} else if (str_contains(strtolower($path_filename), 'create')) {
					// assumption is that a file share was created (physically or linked) based on the path endpoint 
					$fileType = self::FILETYPE_FILE_CREATED;
					$subject = Provider::SUBJECT_SHARED_FILE_CREATE;
				} else {
					// assumption is to catch other events for file downloads then determine thy should be handled
					$fileType = self::FILETYPE_OTHER_DOWNLOADED;
					$subject = Provider::SUBJECT_SHARED_OTHER;
				}
			}
		}

		try {
			$event = $this->activityManager->generateEvent();
			$event->setApp(self::APP_PLUGIN_NAME)
				->setType($fileType)
				->setAffectedUser($owner)
				->setAuthor($this->currentUser->getUID())
				->setTimestamp(time())
				->setSubject($subject, $subjectParams)
				->setObject('files', $fileId, $filePath)
				->setLink($this->urlGenerator->linkToRouteAbsolute('files.view.index', $linkData));
			$this->activityManager->publish($event);
		} catch (\InvalidArgumentException $e) {
			$this->logger->logException($e, [
				'app' => 'files_downloadactivity',
			]);
		} catch (\BadMethodCallException $e) {
			$this->logger->logException($e, [
				'app' => 'files_downloadactivity',
			]);
		}
	}

	/**
	 * @param string $path
	 * @return array
	 * @throws NotFoundException
	 * @throws InvalidPathException
	 */
	protected function getSourcePathAndOwner(string $path): array
	{
		$currentUserId = $this->currentUser->getUID();
		$userFolder = $this->rootFolder->getUserFolder($currentUserId);
		$node = $userFolder->get($path);
		$owner = $node->getOwner()->getUID();

		if ($owner !== $currentUserId) {
			$storage = $node->getStorage();
			if (!$storage->instanceOfStorage('OCA\Files_Sharing\External\Storage')) {
				Filesystem::initMountPoints($owner);
			} else {
				// Probably a remote user, let's try to at least generate activities
				// for the current user
				$owner = $currentUserId;
			}

			$ownerFolder = $this->rootFolder->getUserFolder($owner);
			$nodes = $ownerFolder->getById($node->getId());

			if (empty($nodes)) {
				throw new NotFoundException($node->getPath());
			}

			$node = $nodes[0];
			$path = substr($node->getPath(), strlen($ownerFolder->getPath()));
		}

		return [
			$path,
			$owner,
			$node->getId(),
			$node instanceof Folder
		];
	}
}
