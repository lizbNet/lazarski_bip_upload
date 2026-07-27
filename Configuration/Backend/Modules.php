<?php

return [
    'web_lazarskibipupload' => [
        'parent' => 'web',
        'position' => [],
        'access' => 'user,group',
        'icon' => 'EXT:lazarski_bip_upload/Resources/Public/Icons/Extension.svg',
        'path' => '/module/web/lazarski-bip-upload',
        'labels' => [
            'title' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab',
            'description' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_mod.xlf:mlang_labels_tabdescr',
        ],
        'extensionName' => 'LazarskiBipUpload',
        'controllerActions' => [
            \PrimeServices\LazarskiBipUpload\Controller\DocumentImportController::class => [
                'new', 'create', 'cancel', 'review', 'confirm', 'regenerateSuggestions', 'removeItem', 'generateItem', 'previewItem',
            ],
        ],
    ],
];
