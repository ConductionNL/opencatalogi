<?php

return [
	'resources' => [
		'publication_types' => ['url' => '/api/publication_types'],
		'publications' => ['url' => '/api/publications'],
		'organizations' => ['url' => '/api/organizations'],
		'themes' => ['url' => '/api/themes'],
		'attachments' => ['url' => '/api/attachments'],
		'catalogi' => ['url' => '/api/catalogi'],
		'directory' => ['url' => '/api/directory']
	],
	'routes' => [
		['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
		['name' => 'publication_types#page', 'url' => '/publication_types', 'verb' => 'GET'],
		['name' => 'publications#page', 'url' => '/publications', 'verb' => 'GET'],
		['name' => 'publications#attachments', 'url' => '/api/publications/{id}/attachments', 'verb' => 'GET', 'requirements' => ['id' => '.+']],
		['name' => 'publications#download', 'url' => '/api/publications/{id}/download', 'verb' => 'GET', 'requirements' => ['id' => '.+']],
		['name' => 'catalogi#page', 'url' => '/catalogi', 'verb' => 'GET'],
		['name' => 'search#index', 'url' => '/search', 'verb' => 'GET'],
		['name' => 'search#index', 'url' => '/api/search', 'verb' => 'GET'],
		['name' => 'search#indexInternal', 'url' => '/api/search/internal', 'verb' => 'GET'],
		['name' => 'search#show', 'url' => '/api/search/{id}', 'verb' => 'GET'],
		['name' => 'search#showInternal', 'url' => '/api/search/internal/{id}', 'verb' => 'GET'],
		['name' => 'search#preflighted_cors', 'url' => '/api/{path}', 'verb' => 'OPTIONS', 'requirements' => ['path' => '.+']],
		['name' => 'themes#index', 'url' => '/search/themes', 'verb' => 'GET'],
		['name' => 'themes#index', 'url' => '/api/search/themes', 'verb' => 'GET'],
		['name' => 'themes#indexInternal', 'url' => '/api/themes', 'verb' => 'GET'],
		['name' => 'themes#show', 'url' => '/api/search/themes/{id}', 'verb' => 'GET'],
		['name' => 'themes#showInternal', 'url' => '/api/themes/{id}', 'verb' => 'GET'],
		['name' => 'directory#page', 'url' => '/directory', 'verb' => 'GET'],
		['name' => 'directory#synchronise', 'url' => '/api/directory/{id}/sync', 'verb' => 'GET'],
		['name' => 'configuration#index', 'url' => '/configuration', 'verb' => 'GET'],
		['name' => 'configuration#create', 'url' => '/configuration', 'verb' => 'POST']
	],
];
