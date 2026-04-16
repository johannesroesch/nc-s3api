<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\AppInfo;

use OCA\NcS3Api\Controller\S3Controller;
use OCA\NcS3Api\Auth\AuthService;
use OCA\NcS3Api\Auth\SigV4Validator;
use OCA\NcS3Api\Auth\SigV4Parser;
use OCA\NcS3Api\Auth\PresignedUrlValidator;
use OCA\NcS3Api\Dispatcher\S3Dispatcher;
use OCA\NcS3Api\Dispatcher\OperationResolver;
use OCA\NcS3Api\Handler\BucketHandler;
use OCA\NcS3Api\Handler\ObjectHandler;
use OCA\NcS3Api\Handler\ListingHandler;
use OCA\NcS3Api\Handler\MultipartHandler;
use OCA\NcS3Api\Handler\VersioningHandler;
use OCA\NcS3Api\Handler\TaggingHandler;
use OCA\NcS3Api\Handler\AclHandler;
use OCA\NcS3Api\Handler\CorsHandler;
use OCA\NcS3Api\Handler\EncryptionHandler;
use OCA\NcS3Api\Settings\AdminSection;
use OCA\NcS3Api\Settings\AdminSettings;
use OCA\NcS3Api\Settings\UserSettings;
use OCA\NcS3Api\Storage\BucketService;
use OCA\NcS3Api\Storage\ObjectService;
use OCA\NcS3Api\Storage\StorageMapper;
use OCA\NcS3Api\Xml\XmlWriter;
use OCA\NcS3Api\Xml\XmlReader;
use OCA\NcS3Api\Db\MultipartUploadMapper;
use OCA\NcS3Api\Db\MultipartPartMapper;
use OCA\NcS3Api\Db\ObjectTagMapper;
use OCA\NcS3Api\Db\BucketTagMapper;
use OCA\NcS3Api\Db\PresignedUrlMapper;
use OCA\NcS3Api\Db\BucketMetadataMapper;
use OCA\NcS3Api\Db\CredentialMapper;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'nc_s3api';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerSettingsSection(AdminSection::class);
        $context->registerAdminSettings(AdminSettings::class);
        $context->registerPersonalSettings(UserSettings::class);
    }

    public function boot(IBootContext $context): void {
        // Nothing to do at boot time.
    }
}
