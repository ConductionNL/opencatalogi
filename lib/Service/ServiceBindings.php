<?php

/**
 * The interface bindings of OpenCatalogi.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\InstanceModeSwitchInterface;
use OCA\OpenCatalogi\Contract\AtomFeedServiceInterface;
use OCA\OpenCatalogi\Contract\DiwooCompletenessInterface;
use OCA\OpenCatalogi\Contract\DocumentTypeVocabularyInterface;
use OCA\OpenCatalogi\Contract\PlainSummaryDrafterInterface;
use OCA\OpenCatalogi\Contract\PlainSummaryProvenanceInterface;
use OCA\OpenCatalogi\Contract\CatalogueFeedReaderInterface;
use OCA\OpenCatalogi\Contract\PublishedTableServiceInterface;
use OCA\OpenCatalogi\Contract\AttachmentMigrationServiceInterface;
use OCA\OpenCatalogi\BackgroundJob\AttachmentReadingQueue;
use OCA\OpenCatalogi\Contract\AttachmentReadingQueueInterface;
use OCA\OpenCatalogi\Contract\AttachmentReadingStoreInterface;
use OCA\OpenCatalogi\Contract\RedactedOriginalGuardInterface;
use OCA\OpenCatalogi\Service\AttachmentReading\AttachmentReadingStore;
use OCA\OpenCatalogi\Service\AttachmentReading\RedactedOriginalGuard;
use OCA\OpenCatalogi\Contract\BannerServiceInterface;
use OCA\OpenCatalogi\Contract\BroadcastServiceInterface;
use OCA\OpenCatalogi\Contract\CaseTypeCatalogueServiceInterface;
use OCA\OpenCatalogi\Contract\CatalogAccessServiceInterface;
use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\CitizenCollectionServiceInterface;
use OCA\OpenCatalogi\Contract\CmsMigrationServiceInterface;
use OCA\OpenCatalogi\Contract\CollectionShareServiceInterface;
use OCA\OpenCatalogi\Contract\CommentPeriodServiceInterface;
use OCA\OpenCatalogi\Contract\CommentPeriodWithdrawalServiceInterface;
use OCA\OpenCatalogi\Contract\ConnectionReporterInterface;
use OCA\OpenCatalogi\Contract\DcatSerializerInterface;
use OCA\OpenCatalogi\Contract\DcatDereferenceInterface;
use OCA\OpenCatalogi\Contract\DcatServiceInterface;
use OCA\OpenCatalogi\Contract\DecisionPublicationValidatorInterface;
use OCA\OpenCatalogi\Contract\DemoDataServiceInterface;
use OCA\OpenCatalogi\Contract\DonlRegistrationServiceInterface;
use OCA\OpenCatalogi\Contract\DepublicationServiceInterface;
use OCA\OpenCatalogi\Contract\DirectoryServiceInterface;
use OCA\OpenCatalogi\Contract\EventServiceInterface;
use OCA\OpenCatalogi\Contract\HarvesterReadinessServiceInterface;
use OCA\OpenCatalogi\Contract\IndexRegistrationServiceInterface;
use OCA\OpenCatalogi\Contract\InformationCategoryRegistryInterface;
use OCA\OpenCatalogi\Contract\InformationRequestIntakeInterface;
use OCA\OpenCatalogi\Contract\InformationRequestServiceInterface;
use OCA\OpenCatalogi\Contract\InformationRequestStoreInterface;
use OCA\OpenCatalogi\Contract\InspectionServiceInterface;
use OCA\OpenCatalogi\Contract\MarkupRenderServiceInterface;
use OCA\OpenCatalogi\Contract\NationalIndexServiceInterface;
use OCA\OpenCatalogi\Contract\NoticeBoardServiceInterface;
use OCA\OpenCatalogi\Contract\ObligationReadServiceInterface;
use OCA\OpenCatalogi\Contract\OoapiServiceInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PlooiDeliveryServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationRuleServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenCatalogi\Contract\PubliccodeHarvestServiceInterface;
use OCA\OpenCatalogi\Contract\PublishedCollectionsServiceInterface;
use OCA\OpenCatalogi\Contract\PublishedRecordViewInterface;
use OCA\OpenCatalogi\Contract\QualityServiceInterface;
use OCA\OpenCatalogi\Contract\RetentionServiceInterface;
use OCA\OpenCatalogi\Contract\SavedSearchMatcherInterface;
use OCA\OpenCatalogi\Contract\SavedSearchServiceInterface;
use OCA\OpenCatalogi\Contract\SchemaOrgServiceInterface;
use OCA\OpenCatalogi\Contract\ServiceCatalogueServiceInterface;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenCatalogi\Contract\SitemapServiceInterface;
use OCA\OpenCatalogi\Contract\StandardsVersionServiceInterface;
use OCA\OpenCatalogi\Contract\StatusPageServiceInterface;
use OCA\OpenCatalogi\Contract\StatutoryTermInterface;
use OCA\OpenCatalogi\Contract\TransparencyWorkflowServiceInterface;
use OCA\OpenCatalogi\Contract\UnpublishedOverviewServiceInterface;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Contract\WithheldFromPublicationInterface;
use OCA\OpenCatalogi\Contract\LocalPublicationStateInterface;
use OCA\OpenCatalogi\Contract\PublicationCommentPeriodInterface;
use OCA\OpenCatalogi\Contract\InstanceModeInterface;
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Contract\TermRollInterface;
use OCA\OpenCatalogi\Contract\WooBatchReviewInterface;
use OCA\OpenCatalogi\Service\Woo\WooBatchReview;
use OCA\OpenCatalogi\Service\Publication\PublicationRights;
use OCA\OpenCatalogi\Service\Publication\TermRoll;
use OCA\OpenCatalogi\Service\Publication\LocalPublicationState;
use OCA\OpenCatalogi\Service\Publication\PublicationCommentPeriod;
use OCA\OpenCatalogi\Service\Community\AtomFeedService;
use OCA\OpenCatalogi\Service\Community\CatalogueFeedReader;
use OCA\OpenCatalogi\Service\Table\PublishedTableService;
use OCA\OpenCatalogi\Service\Community\BannerService;
use OCA\OpenCatalogi\Service\Community\MarkupRenderService;
use OCA\OpenCatalogi\Service\Community\NoticeBoardService;
use OCA\OpenCatalogi\Service\Community\StatusPageService;
use OCA\OpenCatalogi\Service\Connection\ConnectionReporter;
use OCA\OpenCatalogi\Service\Portal\CitizenCollectionService;
use OCA\OpenCatalogi\Service\Portal\CollectionShareService;
use OCA\OpenCatalogi\Service\Portal\SavedSearchMatcher;
use OCA\OpenCatalogi\Service\Portal\SavedSearchService;
use OCA\OpenCatalogi\Service\Publication\CommentPeriodService;
use OCA\OpenCatalogi\Service\Publication\CommentPeriodWithdrawalService;
use OCA\OpenCatalogi\Service\Publication\DecisionPublicationValidator;
use OCA\OpenCatalogi\Service\Publication\DepublicationService;
use OCA\OpenCatalogi\Service\Publication\InspectionService;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\Publication\ObligationReadService;
use OCA\OpenCatalogi\Service\Publication\PlooiDeliveryService;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\PublicationStateService;
use OCA\OpenCatalogi\Service\Publication\PublishedCollectionsService;
use OCA\OpenCatalogi\Service\Publication\PublishedRecordView;
use OCA\OpenCatalogi\Service\Withheld\WithheldFromPublication;
use OCA\OpenCatalogi\Service\Woo\StatutoryTerm;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use OCA\OpenCatalogi\Service\Woo\WooRequestIntake;
use OCA\OpenCatalogi\Service\Woo\WooRequestService;
use OCA\OpenCatalogi\Service\Woo\WooRequestStore;
use OCA\OpenCatalogi\Service\Publication\PlainLanguageDrafter;
use OCA\OpenCatalogi\Service\Publication\PlainSummaryProvenance;

/**
 * The interfaces callers in other components depend on, bound to their implementation.
 *
 * Data only: ServiceRegistrar registers each pair with the container, so the
 * table lives beside the registrar instead of in the class the app bootstrap calls.
 *
 * @spec exclude Component wiring; a table of existing classes, no behaviour of its own.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A binding table names every class it binds.
 */
final class ServiceBindings {

	/**
	 * The interfaces callers in other components depend on, bound to their implementation.
	 *
	 * @var array<class-string, class-string>
	 */
	public const INTERFACE_BINDINGS = [
		DirectoryServiceInterface::class => DirectoryService::class,
		PublicationServiceInterface::class => PublicationService::class,
		PublicationQueryServiceInterface::class => PublicationQueryService::class,
		SettingsServiceInterface::class => SettingsService::class,
		InstanceModeSwitchInterface::class => InstanceModeSwitch::class,
		RetentionServiceInterface::class => RetentionService::class,
		TransparencyWorkflowServiceInterface::class => WooService::class,
		CatalogiServiceInterface::class => CatalogiService::class,
		DcatServiceInterface::class => DcatService::class,
		DcatDereferenceInterface::class => DcatDereference::class,
		DonlRegistrationServiceInterface::class => DonlRegistrationService::class,
		UsageCounterServiceInterface::class => UsageCounterService::class,
		UnpublishedOverviewServiceInterface::class => UnpublishedOverviewService::class,
		HarvesterReadinessServiceInterface::class => WooReadinessService::class,
		BroadcastServiceInterface::class => BroadcastService::class,
		SchemaOrgServiceInterface::class => SchemaOrgService::class,
		NationalIndexServiceInterface::class => NationalIndexService::class,
		EventServiceInterface::class => EventService::class,
		PubliccodeHarvestServiceInterface::class => PubliccodeHarvestService::class,
		OoapiServiceInterface::class => OoapiService::class,
		QualityServiceInterface::class => QualityService::class,
		DcatSerializerInterface::class => DcatSerializer::class,
		StatutoryTermInterface::class => StatutoryTerm::class,
		CitizenCollectionServiceInterface::class => CitizenCollectionService::class,
		PublicationRuleServiceInterface::class => PublicationRuleService::class,
		CommentPeriodServiceInterface::class => CommentPeriodService::class,
		PublishedTableServiceInterface::class => PublishedTableService::class,
		CatalogAccessServiceInterface::class => CatalogAccessService::class,
		ConnectionReporterInterface::class => ConnectionReporter::class,
		CommentPeriodWithdrawalServiceInterface::class => CommentPeriodWithdrawalService::class,
		SavedSearchMatcherInterface::class => SavedSearchMatcher::class,
		ObligationReadServiceInterface::class => ObligationReadService::class,
		AttachmentMigrationServiceInterface::class => AttachmentMigrationService::class,
		ServiceCatalogueServiceInterface::class => ServiceCatalogueService::class,
		InformationCategoryRegistryInterface::class => WooCategoryRegistry::class,
		DocumentTypeVocabularyInterface::class => TooiVocabularyService::class,
		DiwooCompletenessInterface::class => SitemapDocumentMapper::class,
		CmsMigrationServiceInterface::class => CmsMigrationService::class,
		InformationRequestServiceInterface::class => WooRequestService::class,
		StandardsVersionServiceInterface::class => StandardsVersionService::class,
		CaseTypeCatalogueServiceInterface::class => CaseTypeCatalogueService::class,
		IndexRegistrationServiceInterface::class => WooRegistrationService::class,
		SavedSearchServiceInterface::class => SavedSearchService::class,
		DemoDataServiceInterface::class => DemoDataService::class,
		WithheldFromPublicationInterface::class => WithheldFromPublication::class,
		LocalPublicationStateInterface::class => LocalPublicationState::class,
		PublicationCommentPeriodInterface::class => PublicationCommentPeriod::class,
		InformationRequestStoreInterface::class => WooRequestStore::class,
		PlooiDeliveryServiceInterface::class => PlooiDeliveryService::class,
		MarkupRenderServiceInterface::class => MarkupRenderService::class,
		DepublicationServiceInterface::class => DepublicationService::class,
		InspectionServiceInterface::class => InspectionService::class,
		StatusPageServiceInterface::class => StatusPageService::class,
		AtomFeedServiceInterface::class => AtomFeedService::class,
		CatalogueFeedReaderInterface::class => CatalogueFeedReader::class,
		InformationRequestIntakeInterface::class => WooRequestIntake::class,
		PublicationStateServiceInterface::class => PublicationStateService::class,
		CollectionShareServiceInterface::class => CollectionShareService::class,
		NoticeBoardServiceInterface::class => NoticeBoardService::class,
		DecisionPublicationValidatorInterface::class => DecisionPublicationValidator::class,
		BannerServiceInterface::class => BannerService::class,
		PublishedCollectionsServiceInterface::class => PublishedCollectionsService::class,
		PublishedRecordViewInterface::class => PublishedRecordView::class,
		SitemapServiceInterface::class => SitemapService::class,
		OpenRegisterLookupInterface::class => OpenRegisterLookup::class,
		AttachmentReadingQueueInterface::class => AttachmentReadingQueue::class,
		AttachmentReadingStoreInterface::class => AttachmentReadingStore::class,
		RedactedOriginalGuardInterface::class => RedactedOriginalGuard::class,
		PlainSummaryDrafterInterface::class => PlainLanguageDrafter::class,
		PlainSummaryProvenanceInterface::class => PlainSummaryProvenance::class,
		TermRollInterface::class => TermRoll::class,
		PublicationRightsInterface::class => PublicationRights::class,
		InstanceModeInterface::class => InstanceMode::class,
		WooBatchReviewInterface::class => WooBatchReview::class,
	];
}//end class
