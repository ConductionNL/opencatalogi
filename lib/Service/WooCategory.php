<?php

/**
 * The 18 information categories of the Woo.
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
 * The Woo information categories, keyed by the code a publication's `wooCategory`
 * stores and the sitemap file names carry. `infocat001` to `infocat017` are the
 * art. 3.3 categories; `infocat018` is the art. 3.1 inspanningsverplichting.
 *
 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-publication-stores-the-woo-information-category-it-belongs-to-req-wpc-001
 */
final class WooCategory {

	/**
	 * The 18 categories with the Dutch and English name of each.
	 *
	 * This holds the English naming only. The value list itself, its TOOI URIs and
	 * the resolver are in {@see TooiVocabularyService}. The set a sitemap is built
	 * from is {@see Woo\WooCategoryRegistry}, which merges these value-list members
	 * with the categories an operator added as data.
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
		'infocat018' => ['nl' => 'Inspanningsverplichting art 3.1 Woo', 'en' => 'Best-efforts obligation under art. 3.1 Woo'],
	];

	/**
	 * The category a publication made from a Woo request is filed under.
	 */
	public const WOO_REQUEST = 'infocat014';
}//end class
