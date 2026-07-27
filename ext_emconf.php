<?php

declare(strict_types=1);

$EM_CONF[$_EXTKEY] = [
    'title' => 'Lazarski BIP Document Upload',
    'description' => 'Backend module for staging and importing sets of Polish institutional documents into hidden BIP pages.',
    'category' => 'module',
    'author' => 'Prime Services',
    'author_email' => '',
    'state' => 'alpha',
    'clearCacheOnLoad' => 0,
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
