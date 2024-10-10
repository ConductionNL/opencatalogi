<?php

return [
	'resources' => [
		'metadata' => ['url' => '/api/metadata'],
		'publications' => ['url' => '/api/publications'],
		'organisations' => ['url' => '/api/organisations'],
		'themes' => ['url' => '/api/themes'],
		'attachments' => ['url' => '/api/attachments'],
		'catalogi' => ['url' => '/api/catalogi'],
		'directory' => ['url' => '/api/directory']
	],
	'routes' => [
		['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
		['name' => 'metadata#page', 'url' => '/metadata', 'verb' => 'GET'],
		['name' => 'publications#page', 'url' => '/publications', 'verb' => 'GET'],
		['name' => 'publications#attachments', 'url' => '/api/publications/{id}/attachments', 'verb' => 'GET', 'requirements' => ['id' => '.+']],
		['name' => 'publications#attachmentsInternal', 'url' => '/api/publications/{id}/attachments/internal', 'verb' => 'GET', 'requirements' => ['id' => '.+']],
		['name' => 'publications#download', 'url' => '/api/publications/{id}/download', 'verb' => 'GET', 'requirements' => ['id' => '.+']],
		['name' => 'catalogi#page', 'url' => '/catalogi', 'verb' => 'GET'],
		['name' => 'search#index', 'url' => '/search', 'verb' => 'GET'],
		['name' => 'search#index', 'url' => '/api/search', 'verb' => 'GET'],
		['name' => 'search#indexInternal', 'url' => '/api/search/internal', 'verb' => 'GET'],
		['name' => 'search#show', 'url' => '/api/search/{id}', 'verb' => 'GET', 'requirements' => ['id' => '.+']],
		['name' => 'search#showInternal', 'url' => '/api/search/internal/{id}', 'verb' => 'GET', 'requirements' => ['id' => '.+']],
		['name' => 'search#preflighted_cors', 'url' => '/api/{path}', 'verb' => 'OPTIONS', 'requirements' => ['path' => '.+']],
		['name' => 'themes#index', 'url' => '/search/themes', 'verb' => 'GET'],
		['name' => 'themes#index', 'url' => '/api/search/themes', 'verb' => 'GET'],
		['name' => 'themes#indexInternal', 'url' => '/api/themes', 'verb' => 'GET'],
		['name' => 'themes#show', 'url' => '/api/search/themes/{id}', 'verb' => 'GET', 'requirements' => ['id' => '.+']],
		['name' => 'themes#showInternal', 'url' => '/api/themes/{id}', 'verb' => 'GET', 'requirements' => ['id' => '.+']],
		['name' => 'directory#page', 'url' => '/directory', 'verb' => 'GET'],
		['name' => 'directory#synchronise', 'url' => '/api/directory/{id}/sync', 'verb' => 'GET', 'requirements' => ['id' => '.+']],
		['name' => 'configuration#index', 'url' => '/configuration', 'verb' => 'GET'],
		['name' => 'configuration#create', 'url' => '/configuration', 'verb' => 'POST']
	],
];
