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
use OCA\OpenCatalogi\Contract\CatalogueFeedReaderInterface;
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
use OCA\OpenCatalogi\Contract\SitemapServiceInterface;
use OCA\OpenCatalogi\Contract\StandardsVersionServiceInterface;
use OCA\OpenCatalogi\Contract\StatusPageServiceInterface;
use OCA\OpenCatalogi\Contract\StatutoryTermInterface;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Contract\WithheldFromPublicationInterface;
use OCA\OpenCatalogi\Contract\InformationCategoryRegistryInterface;
use OCA\OpenCatalogi\Contract\HarvesterReadinessServiceInterface;
use OCA\OpenCatalogi\Contract\IndexRegistrationServiceInterface;
use OCA\OpenCatalogi\Contract\InformationRequestIntakeInterface;
use OCA\OpenCatalogi\Contract\InformationRequestServiceInterface;
use OCA\OpenCatalogi\Contract\InformationRequestStoreInterface;
use OCA\OpenCatalogi\Contract\TransparencyWorkflowServiceInterface;
use OCA\OpenCatalogi\Service\Catalogue\GatewayCaseTypeSourceReader;
use OCA\OpenCatalogi\Service\Community\AtomFeedService;
use OCA\OpenCatalogi\Service\Community\CatalogueFeedReader;
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
	 * The interfaces callers in other components depend on, bound to their implementation.
	 *
	 * @var array<class-string, class-string>
	 */
	private const INTERFACE_BINDINGS = [
		DirectoryServiceInterface::class => DirectoryService::class,
		PublicationServiceInterface::class => PublicationService::class,
		PublicationQueryServiceInterface::class => PublicationQueryService::class,
		SettingsServiceInterface::class => SettingsService::class,
		RetentionServiceInterface::class => RetentionService::class,
		TransparencyWorkflowServiceInterface::class => WooService::class,
		CatalogiServiceInterface::class => CatalogiService::class,
		DcatServiceInterface::class => DcatService::class,
		UsageCounterServiceInterface::class => UsageCounterService::class,
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
		CatalogAccessServiceInterface::class => CatalogAccessService::class,
		ConnectionReporterInterface::class => ConnectionReporter::class,
		CommentPeriodWithdrawalServiceInterface::class => CommentPeriodWithdrawalService::class,
		SavedSearchMatcherInterface::class => SavedSearchMatcher::class,
		ObligationReadServiceInterface::class => ObligationReadService::class,
		AttachmentMigrationServiceInterface::class => AttachmentMigrationService::class,
		ServiceCatalogueServiceInterface::class => ServiceCatalogueService::class,
		InformationCategoryRegistryInterface::class => WooCategoryRegistry::class,
		CmsMigrationServiceInterface::class => CmsMigrationService::class,
		InformationRequestServiceInterface::class => WooRequestService::class,
		StandardsVersionServiceInterface::class => StandardsVersionService::class,
		CaseTypeCatalogueServiceInterface::class => CaseTypeCatalogueService::class,
		IndexRegistrationServiceInterface::class => WooRegistrationService::class,
		SavedSearchServiceInterface::class => SavedSearchService::class,
		DemoDataServiceInterface::class => DemoDataService::class,
		WithheldFromPublicationInterface::class => WithheldFromPublication::class,
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
		SitemapServiceInterface::class => SitemapService::class,
	];

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

		$this->registerPublishedCollections(context: $context);

		foreach (self::INTERFACE_BINDINGS as $interface => $implementation) {
			$context->registerServiceAlias($interface, $implementation);
		}

	}//end register()

	/**
	 * Register the published-collection service, scoped to this app's config.
	 *
	 * The configuration lives in this app's config, so adding a collection takes
	 * effect on a running instance without a release.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Component wiring; binds an existing service, no behaviour of its own.
	 */
	private function registerPublishedCollections(IRegistrationContext $context): void {
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

	}//end registerPublishedCollections()
}//end class
