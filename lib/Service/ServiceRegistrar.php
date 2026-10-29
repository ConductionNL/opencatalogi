<?php

/**
 * Registers the service bindings of OpenCatalogi.
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

use OCA\OpenCatalogi\Contract\AtomFeedServiceInterface;
use OCA\OpenCatalogi\Contract\AttachmentMigrationServiceInterface;
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
use OCA\OpenCatalogi\Contract\DcatServiceInterface;
use OCA\OpenCatalogi\Contract\DecisionPublicationValidatorInterface;
use OCA\OpenCatalogi\Contract\DemoDataServiceInterface;
use OCA\OpenCatalogi\Contract\DepublicationServiceInterface;
use OCA\OpenCatalogi\Contract\DirectoryServiceInterface;
use OCA\OpenCatalogi\Contract\EventServiceInterface;
use OCA\OpenCatalogi\Contract\InspectionServiceInterface;
use OCA\OpenCatalogi\Contract\MarkupRenderServiceInterface;
use OCA\OpenCatalogi\Contract\NationalIndexServiceInterface;
use OCA\OpenCatalogi\Contract\NoticeBoardServiceInterface;
use OCA\OpenCatalogi\Contract\ObligationReadServiceInterface;
use OCA\OpenCatalogi\Contract\OoapiServiceInterface;
use OCA\OpenCatalogi\Contract\PlooiDeliveryServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationRuleServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenCatalogi\Contract\PubliccodeHarvestServiceInterface;
use OCA\OpenCatalogi\Contract\PublishedCollectionsServiceInterface;
use OCA\OpenCatalogi\Contract\QualityServiceInterface;
use OCA\OpenCatalogi\Contract\RetentionServiceInterface;
use OCA\OpenCatalogi\Contract\SavedSearchMatcherInterface;
use OCA\OpenCatalogi\Contract\SavedSearchServiceInterface;
use OCA\OpenCatalogi\Contract\SchemaOrgServiceInterface;
use OCA\OpenCatalogi\Contract\ServiceCatalogueServiceInterface;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenCatalogi\Contract\StandardsVersionServiceInterface;
use OCA\OpenCatalogi\Contract\StatusPageServiceInterface;
use OCA\OpenCatalogi\Contract\StatutoryTermInterface;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Contract\WithheldFromPublicationInterface;
use OCA\OpenCatalogi\Contract\WooCategoryRegistryInterface;
use OCA\OpenCatalogi\Contract\WooReadinessServiceInterface;
use OCA\OpenCatalogi\Contract\WooRegistrationServiceInterface;
use OCA\OpenCatalogi\Contract\WooRequestIntakeInterface;
use OCA\OpenCatalogi\Contract\WooRequestServiceInterface;
use OCA\OpenCatalogi\Contract\WooRequestStoreInterface;
use OCA\OpenCatalogi\Contract\WooServiceInterface;
use OCA\OpenCatalogi\Service\Catalogue\GatewayCaseTypeSourceReader;
use OCA\OpenCatalogi\Service\Community\AtomFeedService;
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
use OCA\OpenCatalogi\Service\Withheld\WithheldFromPublication;
use OCA\OpenCatalogi\Service\Woo\StatutoryTerm;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use OCA\OpenCatalogi\Service\Woo\WooRequestIntake;
use OCA\OpenCatalogi\Service\Woo\WooRequestService;
use OCA\OpenCatalogi\Service\Woo\WooRequestStore;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the service bindings of OpenCatalogi.
 *
 * Services that autowire need no entry here. This class holds the bindings the
 * container cannot infer: interfaces bound to their implementation, and
 * services that take a scalar constructor argument.
 *
 * @spec exclude Component wiring; registers existing classes, no behaviour of its own.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A wiring class names every class it binds.
 */
class ServiceRegistrar {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id, passed to services that are scoped to it.
	 */
	public function __construct(
		private readonly string $appName,
	) {

	}//end __construct()

	/**
	 * Register the service bindings.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Component wiring; binds existing services, no behaviour of its own.
	 */
	public function register(IRegistrationContext $context): void {
		// The external case type catalogue is read through integriq's gateway;
		// this app composes the ask and reads the answer, and holds no
		// transport. The reader raises rather than answering an empty list when
		// there is no gateway to ask with.
		$context->registerServiceAlias(
			'OCA\\OpenCatalogi\\Service\\Catalogue\\CaseTypeSourceReader',
			GatewayCaseTypeSourceReader::class
		);

		// The published-collection configuration lives in this app's config, so
		// adding a collection takes effect on a running instance without a
		// release.
		$appName = $this->appName;
		$context->registerService(
			PublishedCollectionsService::class,
			static function ($c) use ($appName) {
				return new PublishedCollectionsService(
					config: $c->get(\OCP\IAppConfig::class),
					ruleService: $c->get(PublicationRuleService::class),
					appName: $appName
				);
			}
		);

		// Callers in other components depend on these interfaces.
		$context->registerServiceAlias(DirectoryServiceInterface::class, DirectoryService::class);
		$context->registerServiceAlias(PublicationServiceInterface::class, PublicationService::class);
		$context->registerServiceAlias(PublicationQueryServiceInterface::class, PublicationQueryService::class);
		$context->registerServiceAlias(SettingsServiceInterface::class, SettingsService::class);
		$context->registerServiceAlias(RetentionServiceInterface::class, RetentionService::class);
		$context->registerServiceAlias(WooServiceInterface::class, WooService::class);
		$context->registerServiceAlias(CatalogiServiceInterface::class, CatalogiService::class);
		$context->registerServiceAlias(DcatServiceInterface::class, DcatService::class);
		$context->registerServiceAlias(UsageCounterServiceInterface::class, UsageCounterService::class);
		$context->registerServiceAlias(WooReadinessServiceInterface::class, WooReadinessService::class);
		$context->registerServiceAlias(BroadcastServiceInterface::class, BroadcastService::class);
		$context->registerServiceAlias(SchemaOrgServiceInterface::class, SchemaOrgService::class);
		$context->registerServiceAlias(NationalIndexServiceInterface::class, NationalIndexService::class);
		$context->registerServiceAlias(EventServiceInterface::class, EventService::class);
		$context->registerServiceAlias(PubliccodeHarvestServiceInterface::class, PubliccodeHarvestService::class);
		$context->registerServiceAlias(OoapiServiceInterface::class, OoapiService::class);
		$context->registerServiceAlias(QualityServiceInterface::class, QualityService::class);
		$context->registerServiceAlias(DcatSerializerInterface::class, DcatSerializer::class);
		$context->registerServiceAlias(StatutoryTermInterface::class, StatutoryTerm::class);
		$context->registerServiceAlias(CitizenCollectionServiceInterface::class, CitizenCollectionService::class);
		$context->registerServiceAlias(PublicationRuleServiceInterface::class, PublicationRuleService::class);
		$context->registerServiceAlias(CommentPeriodServiceInterface::class, CommentPeriodService::class);
		$context->registerServiceAlias(CatalogAccessServiceInterface::class, CatalogAccessService::class);
		$context->registerServiceAlias(ConnectionReporterInterface::class, ConnectionReporter::class);
		$context->registerServiceAlias(CommentPeriodWithdrawalServiceInterface::class, CommentPeriodWithdrawalService::class);
		$context->registerServiceAlias(SavedSearchMatcherInterface::class, SavedSearchMatcher::class);
		$context->registerServiceAlias(ObligationReadServiceInterface::class, ObligationReadService::class);
		$context->registerServiceAlias(AttachmentMigrationServiceInterface::class, AttachmentMigrationService::class);
		$context->registerServiceAlias(ServiceCatalogueServiceInterface::class, ServiceCatalogueService::class);
		$context->registerServiceAlias(WooCategoryRegistryInterface::class, WooCategoryRegistry::class);
		$context->registerServiceAlias(CmsMigrationServiceInterface::class, CmsMigrationService::class);
		$context->registerServiceAlias(WooRequestServiceInterface::class, WooRequestService::class);
		$context->registerServiceAlias(StandardsVersionServiceInterface::class, StandardsVersionService::class);
		$context->registerServiceAlias(CaseTypeCatalogueServiceInterface::class, CaseTypeCatalogueService::class);
		$context->registerServiceAlias(WooRegistrationServiceInterface::class, WooRegistrationService::class);
		$context->registerServiceAlias(SavedSearchServiceInterface::class, SavedSearchService::class);
		$context->registerServiceAlias(DemoDataServiceInterface::class, DemoDataService::class);
		$context->registerServiceAlias(WithheldFromPublicationInterface::class, WithheldFromPublication::class);
		$context->registerServiceAlias(WooRequestStoreInterface::class, WooRequestStore::class);
		$context->registerServiceAlias(PlooiDeliveryServiceInterface::class, PlooiDeliveryService::class);
		$context->registerServiceAlias(MarkupRenderServiceInterface::class, MarkupRenderService::class);
		$context->registerServiceAlias(DepublicationServiceInterface::class, DepublicationService::class);
		$context->registerServiceAlias(InspectionServiceInterface::class, InspectionService::class);
		$context->registerServiceAlias(StatusPageServiceInterface::class, StatusPageService::class);
		$context->registerServiceAlias(AtomFeedServiceInterface::class, AtomFeedService::class);
		$context->registerServiceAlias(WooRequestIntakeInterface::class, WooRequestIntake::class);
		$context->registerServiceAlias(PublicationStateServiceInterface::class, PublicationStateService::class);
		$context->registerServiceAlias(CollectionShareServiceInterface::class, CollectionShareService::class);
		$context->registerServiceAlias(NoticeBoardServiceInterface::class, NoticeBoardService::class);
		$context->registerServiceAlias(DecisionPublicationValidatorInterface::class, DecisionPublicationValidator::class);
		$context->registerServiceAlias(BannerServiceInterface::class, BannerService::class);
		$context->registerServiceAlias(PublishedCollectionsServiceInterface::class, PublishedCollectionsService::class);

	}//end register()
}//end class
