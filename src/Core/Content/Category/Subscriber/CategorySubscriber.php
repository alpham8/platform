<?php declare(strict_types=1);

namespace Shopware\Core\Content\Category\Subscriber;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Category\Aggregate\CategoryTranslation\CategoryTranslationDefinition;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Category\SalesChannel\SalesChannelCategoryEntity;
use Shopware\Core\Content\Category\Service\AbstractCategoryUrlGenerator;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelEntityLoadedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @internal
 */
#[Package('discovery')]
class CategorySubscriber implements EventSubscriberInterface
{
    final public const VIOLATION_LINK_MEDIA_NOT_FOUND = 'CONTENT__CATEGORY_LINK_MEDIA_NOT_FOUND';

    /**
     * @internal
     */
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly AbstractCategoryUrlGenerator $categoryUrlGenerator,
        private readonly Connection $connection,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'sales_channel.category.loaded' => 'salesChannelCategoryLoaded',
            EntityWriteEvent::class => 'beforeWriteCategory',
            PreWriteValidationEvent::class => 'preValidate',
        ];
    }

    /**
     * @param SalesChannelEntityLoadedEvent<SalesChannelCategoryEntity> $event
     */
    public function salesChannelCategoryLoaded(SalesChannelEntityLoadedEvent $event): void
    {
        $salesChannel = $event->getSalesChannelContext()->getSalesChannel();

        foreach ($event->getEntities() as $category) {
            $category->assign([
                'seoUrl' => $this->categoryUrlGenerator->generate($category, $salesChannel),
            ]);
        }
    }

    public function preValidate(PreWriteValidationEvent $event): void
    {
        $mediaIds = [];

        foreach ($event->getCommands() as $command) {
            if (!$command instanceof InsertCommand && !$command instanceof UpdateCommand) {
                continue;
            }

            if ($command->getEntityName() !== CategoryTranslationDefinition::ENTITY_NAME) {
                continue;
            }

            $payload = $command->getPayload();

            if (!isset($payload['link_media_id'])) {
                continue;
            }

            $mediaId = Uuid::fromBytesToHex($payload['link_media_id']);
            $mediaIds[$mediaId] = $command->getPath();
        }

        if (\count($mediaIds) === 0) {
            return;
        }

        $existingIds = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(`id`)) FROM `media` WHERE `id` IN (:ids)',
            ['ids' => array_map([Uuid::class, 'fromHexToBytes'], array_keys($mediaIds))],
            ['ids' => ArrayParameterType::BINARY]
        );

        $violations = new ConstraintViolationList();
        $messageTemplate = 'The media entity with id "{{ id }}" does not exist.';

        foreach ($mediaIds as $mediaId => $path) {
            if (!\in_array($mediaId, $existingIds, true)) {
                $parameters = ['{{ id }}' => $mediaId];
                $violations->add(new ConstraintViolation(
                    str_replace(array_keys($parameters), array_values($parameters), $messageTemplate),
                    $messageTemplate,
                    $parameters,
                    null,
                    $path . '/linkMediaId',
                    $mediaId,
                    null,
                    self::VIOLATION_LINK_MEDIA_NOT_FOUND,
                ));
            }
        }

        if ($violations->count() > 0) {
            $event->getExceptions()->add(new WriteConstraintViolationException($violations));
        }
    }

    public function beforeWriteCategory(EntityWriteEvent $event): void
    {
        $commands = $event->getCommandsForEntity(CategoryDefinition::ENTITY_NAME);
        if ($commands === []) {
            return;
        }

        $defaultCmsPageId = $this->getValidDefaultCmsPageId();
        if ($defaultCmsPageId === null) {
            return;
        }

        $defaultCmsPageIdBytes = Uuid::fromHexToBytes($defaultCmsPageId);

        foreach ($commands as $command) {
            if ($command instanceof DeleteCommand) {
                continue;
            }

            if ($command instanceof InsertCommand) {
                if (!$command->hasField('cms_page_id') || $command->getPayload()['cms_page_id'] === null) {
                    $command->addPayload('cms_page_id', $defaultCmsPageIdBytes);
                }

                continue;
            }

            if ($command instanceof UpdateCommand) {
                if ($command->hasField('cms_page_id') && $command->getPayload()['cms_page_id'] === null) {
                    $command->addPayload('cms_page_id', $defaultCmsPageIdBytes);
                }
            }
        }
    }

    private function getValidDefaultCmsPageId(): ?string
    {
        $defaultCmsPageId = $this->systemConfigService->getString(CategoryDefinition::CONFIG_KEY_DEFAULT_CMS_PAGE_CATEGORY);
        if ($defaultCmsPageId === '' || !Uuid::isValid($defaultCmsPageId)) {
            return null;
        }

        if (!$this->cmsPageExists($defaultCmsPageId)) {
            return null;
        }

        return $defaultCmsPageId;
    }

    private function cmsPageExists(string $cmsPageId): bool
    {
        $cmsPageIdResult = $this->connection->fetchOne(
            'SELECT id FROM cms_page WHERE id = :cmsPageId AND version_id = :versionId LIMIT 1;',
            [
                'cmsPageId' => Uuid::fromHexToBytes($cmsPageId),
                'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ]
        );

        return $cmsPageIdResult !== false;
    }
}
