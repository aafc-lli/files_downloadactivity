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

use OCP\Activity\IEvent;
use OCP\Activity\IEventMerger;
use OCP\Activity\IManager;
use OCP\Activity\IProvider;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\ILogger;

class Provider implements IProvider
{

	/** @var IFactory */
	protected $languageFactory;

	/** @var IL10N */
	protected $l;

	/** @var IURLGenerator */
	protected $url;

	/** @var IManager */
	protected $activityManager;

	/** @var IUserManager */
	protected $userManager;

	/** @var IEventMerger */
	protected $eventMerger;

	/** @var array */
	protected $displayNames = [];

	/** @var string */
	protected $lastType = '';

	/** @var ILogger */
	protected $logger;

	public const SUBJECT_SHARED_FILE_DOWNLOADED = 'shared_file_downloaded';
	public const SUBJECT_SHARED_FOLDER_DOWNLOADED = 'shared_folder_downloaded';
	public const SUBJECT_FILE_SELF = 'file_self';
	public const SUBJECT_FOLDER_SELF = 'folder_self';
	public const SUBJECT_SHARED_FILE = 'shared_file';
	public const SUBJECT_SHARED_FOLDER = 'shared_folder';
	public const SUBJECT_SHARED_FILE_PREVIEW = 'shared_file_preview';
	public const SUBJECT_SHARED_FILE_CREATE = 'shared_file_create';
	public const SUBJECT_SHARED_FILE_ICON = 'shared_file_icon';
	public const SUBJECT_SHARED_OTHER = 'shared_other';

	/**
	 * @param IFactory $languageFactory
	 * @param IURLGenerator $url
	 * @param IManager $activityManager
	 * @param IUserManager $userManager
	 * @param IEventMerger $eventMerger
	 */
	public function __construct(IFactory $languageFactory, IURLGenerator $url, IManager $activityManager, IUserManager $userManager, IEventMerger $eventMerger, ILogger $logger)
	{
		$this->languageFactory = $languageFactory;
		$this->url = $url;
		$this->activityManager = $activityManager;
		$this->userManager = $userManager;
		$this->eventMerger = $eventMerger;
		$this->logger = $logger;
	}

	/**
	 * @param string $language
	 * @param IEvent $event
	 * @param IEvent|null $previousEvent
	 * @return IEvent
	 * @throws \InvalidArgumentException
	 * @since 11.0.0
	 */
	public function parse($language, IEvent $event, IEvent $previousEvent = null): IEvent
	{
		if ($event->getApp() !== 'files_downloadactivity') {
			throw new \InvalidArgumentException();
		}
		if ($event->getType() !== 'file_downloaded') {
			throw new \InvalidArgumentException();
		}

		$this->l = $this->languageFactory->get('files_downloadactivity', $language);
		if (method_exists($this->activityManager, 'getRequirePNG') && $this->activityManager->getRequirePNG()) {
			$event->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'actions/share.png')));
		} else {
			$event->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'actions/share.svg')));
		}

		if ($this->activityManager->isFormattingFilteredObject()) {
			try {
				return $this->parseShortVersion($event, $previousEvent);
			} catch (\InvalidArgumentException $e) {
				// Ignore and simply use the long version...
			}
		}

		return $this->parseLongVersion($event, $previousEvent);
	}

	/**
	 * @param IEvent $event
	 * @param IEvent|null $previousEvent
	 * @return IEvent
	 * @throws \InvalidArgumentException
	 * @since 11.0.0
	 */
	public function parseShortVersion(IEvent $event, IEvent $previousEvent = null): IEvent
	{
		$parsedParameters = $this->getParsedParameters($event);
		$params = $event->getSubjectParameters();

		if ($event->getType() === 'file_downloaded') {
			if ($params[2] === 'desktop') {
				$subject = $this->l->t('Downloaded by {actor} (via desktop)');
			} elseif ($params[2] === 'mobile') {
				$subject = $this->l->t('Downloaded by {actor} (via app)');
			} else {
				$subject = $this->l->t('Downloaded by {actor} (via browser)');
			}
		} else {
			if ($params[2] === 'desktop') {
				$subject = $this->l->t('Accessed by {actor} (via desktop)');
			} elseif ($params[2] === 'mobile') {
				$subject = $this->l->t('Accessed by {actor} (via app)');
			} else {
				$subject = $this->l->t('Accessed by {actor} (via browser)');
			}
		}

		$this->setSubjects($event, $subject, $parsedParameters);

		if ($this->lastType !== $params[2]) {
			$this->lastType = $params[2];
			return $event;
		}

		return $this->eventMerger->mergeEvents('actor', $event, $previousEvent);
	}

	/**
	 * @param IEvent $event
	 * @param IEvent|null $previousEvent
	 * @return IEvent
	 * @throws \InvalidArgumentException
	 * @since 11.0.0
	 */
	public function parseLongVersion(IEvent $event, IEvent $previousEvent = null): IEvent
	{
		$parsedParameters = $this->getParsedParameters($event);
		$params = $event->getSubjectParameters();

		if ($event->getType() === 'file_downloaded') {
			if ($event->getAuthor() === $event->getAffectedUser()) {
				if ($params[2] === 'desktop') {
					$subject = $this->l->t('You downloaded {file} via the desktop client');
				} elseif ($params[2] === 'mobile') {
					$subject = $this->l->t('You downloaded {file} via the mobile app');
				} else {
					$subject = $this->l->t('You downloaded {file} via the browser');
				}
			} else {
				if ($params[2] === 'desktop') {
					$subject = $this->l->t('Shared file {file} was downloaded by {actor} via the desktop client');
				} elseif ($params[2] === 'mobile') {
					$subject = $this->l->t('Shared file {file} was downloaded by {actor} via the mobile app');
				} else {
					$subject = $this->l->t('Shared file {file} was downloaded by {actor} via the browser');
				}
			}
		} else {
			if ($params[2] === 'desktop') {
				$subject = $this->l->t('File {file} accessed by {actor} via the desktop client');
			} elseif ($params[2] === 'mobile') {
				$subject = $this->l->t('File {file} accessed by {actor} via the mobile app');
			} else {
				$subject = $this->l->t('File {file} accessed by {actor} via the browser');
			}
		}

		$this->setSubjects($event, $subject, $parsedParameters);

		if ($this->lastType !== $params[2]) {
			$this->lastType = $params[2];
			return $event;
		}

		$event = $this->eventMerger->mergeEvents('actor', $event, $previousEvent);
		if ($event->getChildEvent() === null) {
			$event = $this->eventMerger->mergeEvents('file', $event, $previousEvent);
		}

		return $event;
	}

	/**
	 * @param IEvent $event
	 * @param string $subject
	 * @param array $parameters
	 * @throws \InvalidArgumentException
	 */
	protected function setSubjects(IEvent $event, string $subject, array $parameters): void
	{
		$placeholders = $replacements = [];
		foreach ($parameters as $placeholder => $parameter) {
			$placeholders[] = '{' . $placeholder . '}';
			if ($parameter['type'] === 'file') {
				$replacements[] = $parameter['path'];
			} else {
				$replacements[] = $parameter['name'];
			}
		}

		$event->setParsedSubject(str_replace($placeholders, $replacements, $subject))
			->setRichSubject($subject, $parameters);
	}

	protected function getParsedParameters(IEvent $event): array
	{
		$subject = $event->getSubject();
		$parameters = $event->getSubjectParameters();

		switch ($subject) {
			case self::SUBJECT_SHARED_FOLDER_DOWNLOADED:
			case self::SUBJECT_SHARED_FILE_DOWNLOADED:
			case self::SUBJECT_FILE_SELF;
			case self::SUBJECT_FOLDER_SELF;
			case self::SUBJECT_SHARED_FILE:
			case self::SUBJECT_SHARED_FOLDER:
			case self::SUBJECT_SHARED_FILE_PREVIEW:
			case self::SUBJECT_SHARED_FILE_CREATE:
			case self::SUBJECT_SHARED_FILE_ICON:
			case self::SUBJECT_SHARED_OTHER:

				$id = key($parameters[0]);
				return [
					'file' => $this->generateFileParameter((int) $id, $parameters[0][$id]),
					'actor' => $this->generateUserParameter($parameters[1]),
				];
		}
		return [];
	}

	/**
	 * @param int $id
	 * @param string $path
	 * @return array
	 */
	protected function generateFileParameter(int $id, string $path): array
	{
		return [
			'type' => 'file',
			'id' => $id,
			'name' => basename($path),
			'path' => $path,
			'link' => $this->url->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $id]),
		];
	}

	/**
	 * @param string $uid
	 * @return array
	 */
	protected function generateUserParameter(string $uid): array
	{
		if (!isset($this->displayNames[$uid])) {
			$this->displayNames[$uid] = $this->getDisplayName($uid);
		}

		return [
			'type' => 'user',
			'id' => $uid,
			'name' => $this->displayNames[$uid],
		];
	}

	/**
	 * @param string $uid
	 * @return string
	 */
	protected function getDisplayName(string $uid): string
	{
		$user = $this->userManager->get($uid);
		if ($user instanceof IUser) {
			return $user->getDisplayName();
		}

		return $uid;
	}
}
