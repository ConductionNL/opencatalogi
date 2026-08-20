// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Icon registry for opencatalogi (ADR-077 semantic icon vocabulary).
//
// CnAppNav, CnIcon, CnIndexPage / CnDetailPage headers and empty states resolve
// an `icon` by PascalCase name through the registry that `registerIcons()`
// populates. A name that is not registered renders NO icon in the navigation —
// not a fallback glyph — so this file must cover every `icon` the manifests and
// register files name. Keep it in sync when you add a menu entry.
//
// Generated from the app's own manifests; every name is verified to exist in
// vue-material-design-icons.

import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariant.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import Bookshelf from 'vue-material-design-icons/Bookshelf.vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import FolderMultiple from 'vue-material-design-icons/FolderMultiple.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import History from 'vue-material-design-icons/History.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import MapMarkerPath from 'vue-material-design-icons/MapMarkerPath.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import Package from 'vue-material-design-icons/Package.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import ShieldAccountOutline from 'vue-material-design-icons/ShieldAccountOutline.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import TagOutline from 'vue-material-design-icons/TagOutline.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import Web from 'vue-material-design-icons/Web.vue'

export default {
	AccountGroup,
	BookOpenVariant,
	BookOpenVariantOutline,
	Bookshelf,
	ChartBar,
	Cog,
	CogOutline,
	FileDocument,
	Folder,
	FolderMultiple,
	FolderOutline,
	FormatListBulleted,
	History,
	LinkVariant,
	Magnify,
	MapMarkerPath,
	OfficeBuilding,
	Package,
	PackageVariantClosed,
	PlayCircleOutline,
	ShieldAccountOutline,
	Sitemap,
	TagOutline,
	Tune,
	ViewDashboardOutline,
	Web,
}
