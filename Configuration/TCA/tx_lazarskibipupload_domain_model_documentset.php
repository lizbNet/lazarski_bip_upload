<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset',
        'label' => 'uid',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'cruser_id' => 'cruser_id',
        'delete' => 'deleted',
        'hideTable' => true,
        'rootLevel' => -1,
        'searchFields' => '',
    ],
    'types' => [
        '1' => ['showitem' => 'staging_token, status, expires_at, confirmed_page, cruser_id, suggested_type, type_confidence, suggested_page_title, suggested_subtitle, suggested_slug, analysis_payload, approved_type, approved_page_title, approved_subtitle, approved_slug, approved_parent_page, approved_fal_folder, suggested_auto_folder, include_auto_folder, approved_file_prefix'],
    ],
    'columns' => [
        'cruser_id' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.cruser_id',
            'config' => [
                'type' => 'number',
                'format' => 'integer',
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'staging_token' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.staging_token',
            'config' => [
                'type' => 'input',
                'size' => 32,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
        'status' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Draft', 'value' => 0],
                    ['label' => 'Staged', 'value' => 1],
                    ['label' => 'Confirmed', 'value' => 2],
                    ['label' => 'Published', 'value' => 3],
                    ['label' => 'Cancelled', 'value' => 4],
                    ['label' => 'Expired', 'value' => 5],
                ],
                'default' => 0,
            ],
        ],
        'expires_at' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.expires_at',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
            ],
        ],
        'confirmed_page' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.confirmed_page',
            'config' => [
                'type' => 'number',
                'format' => 'integer',
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'suggested_type' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.suggested_type',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
        'type_confidence' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.type_confidence',
            'config' => [
                'type' => 'number',
                'format' => 'integer',
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'suggested_page_title' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.suggested_page_title',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
        'suggested_subtitle' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.suggested_subtitle',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'readOnly' => true,
            ],
        ],
        'suggested_slug' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.suggested_slug',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
        'analysis_payload' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.analysis_payload',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 8,
                'readOnly' => true,
            ],
        ],
        'approved_type' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.approved_type',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'eval' => 'trim',
            ],
        ],
        'approved_page_title' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.approved_page_title',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
        'approved_subtitle' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.approved_subtitle',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
            ],
        ],
        'approved_slug' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.approved_slug',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
        'approved_parent_page' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.approved_parent_page',
            'config' => [
                'type' => 'number',
                'format' => 'integer',
                'default' => 0,
            ],
        ],
        'approved_fal_folder' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.approved_fal_folder',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
        'suggested_auto_folder' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.suggested_auto_folder',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
        'include_auto_folder' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.include_auto_folder',
            'config' => [
                'type' => 'check',
                'default' => 1,
            ],
        ],
        'approved_file_prefix' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.approved_file_prefix',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
    ],
];
