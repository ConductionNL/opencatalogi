<?php

/**
 * The 17 information categories of the Woo.
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
 *
 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-publication-stores-the-woo-information-category-it-belongs-to-req-wpc-001
 */

namespace OCA\OpenCatalogi\Service;

/**
 * The Woo information categories (art. 3.3), keyed by the code a publication's
 * `wooCategory` stores and the sitemap file names carry (`SitemapService::INFO_CAT`).
 *
 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-publication-stores-the-woo-information-category-it-belongs-to-req-wpc-001
 */
final class WooCategory {

	/**
	 * The 17 categories with the Dutch and English name of each.
	 *
	 * @var array<string, array{nl: string, en: string}>
	 */
	public const ALL = [
		'infocat001' => ['nl' => 'Wetten en algemeen verbindende voorschriften', 'en' => 'Laws and generally binding regulations'],
		'infocat002' => ['nl' => 'Overige besluiten van algemene strekking', 'en' => 'Other decisions of general scope'],
		'infocat003' => ['nl' => 'Ontwerpen van wet- en regelgeving met adviesaanvraag', 'en' => 'Draft legislation sent out for advice'],
		'infocat004' => ['nl' => 'Organisatie en werkwijze', 'en' => 'Organisation and working methods'],
		'infocat005' => ['nl' => 'Bereikbaarheidsgegevens', 'en' => 'Contact details'],
		'infocat006' => ['nl' => 'Bij vertegenwoordigende organen ingekomen stukken', 'en' => 'Documents received by representative bodies'],
		'infocat007' => ['nl' => 'Vergaderstukken Staten-Generaal', 'en' => 'Meeting documents of the States General'],
		'infocat008' => ['nl' => 'Vergaderstukken decentrale overheden', 'en' => 'Meeting documents of local and regional governments'],
		'infocat009' => ['nl' => 'Agenda\'s en besluitenlijsten bestuurscolleges', 'en' => 'Agendas and decision lists of executive boards'],
		'infocat010' => ['nl' => 'Adviezen', 'en' => 'Advice'],
		'infocat011' => ['nl' => 'Convenanten', 'en' => 'Covenants'],
		'infocat012' => ['nl' => 'Jaarplannen en jaarverslagen', 'en' => 'Annual plans and annual reports'],
		'infocat013' => ['nl' => 'Subsidieverplichtingen anders dan met beschikking', 'en' => 'Subsidy obligations other than by decision'],
		'infocat014' => ['nl' => 'Woo-verzoeken en -besluiten', 'en' => 'Woo requests and decisions'],
		'infocat015' => ['nl' => 'Onderzoeksrapporten', 'en' => 'Research reports'],
		'infocat016' => ['nl' => 'Beschikkingen', 'en' => 'Individual decisions'],
		'infocat017' => ['nl' => 'Klachtoordelen', 'en' => 'Complaint rulings'],
	];

	/**
	 * The category a publication made from a Woo request is filed under.
	 */
	public const WOO_REQUEST = 'infocat014';
}//end class
