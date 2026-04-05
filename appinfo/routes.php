<?php

declare(strict_types=1);

return [
	'routes' => [
		[
			'name' => 'debug#ping',
			'url' => '/ping',
			'verb' => 'GET',
		],
		[
			'name' => 'extraction#extract',
			'url' => '/extract',
			'verb' => 'POST',
		],
	],
];
