<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset',
        'label' => 'uid',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'hideTable' => true,
        'rootLevel' => -1,
    ],
    'types' => [
        '1' => ['showitem' => 'staging_token, status, expires_at, confirmed_page, cruser_id, suggested_type, type_confidence, suggested_page_title, suggested_subtitle, suggested_slug, analysis_payload, approved_type, approved_page_title, approved_subtitle, approved_slug, approved_parent_page, approved_fal_folder, suggested_auto_folder, include_auto_folder, approved_file_prefix, approved_author, approved_start_date'],
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
                'searchable' => false,
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
                'searchable' => false,
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
                'searchable' => false,
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
                'searchable' => false,
            ],
        ],
        'suggested_subtitle' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.suggested_subtitle',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'suggested_slug' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.suggested_slug',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'analysis_payload' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.analysis_payload',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 8,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'approved_type' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.approved_type',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'eval' => 'trim',
                'searchable' => false,
            ],
        ],
        'approved_page_title' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.approved_page_title',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'searchable' => false,
            ],
        ],
        'approved_subtitle' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.approved_subtitle',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'searchable' => false,
            ],
        ],
        'approved_slug' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.approved_slug',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'searchable' => false,
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
                'searchable' => false,
            ],
        ],
        'suggested_auto_folder' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.suggested_auto_folder',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'eval' => 'trim',
                'readOnly' => true,
                'searchable' => false,
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
                'searchable' => false,
            ],
        ],
        'approved_start_date' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.approved_start_date',
            'config' => [
                'type' => 'datetime',
                'format' => 'date',
                'default' => 0,
                'searchable' => false,
            ],
        ],
        'approved_author' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentset.approved_author',
            'config' => [
                'type' => 'input',
                // Matches pages.author (varchar(255), max 255) - the field this value is
                // eventually copied into at publish time.
                'max' => 255,
                'size' => 40,
                'eval' => 'trim',
                'searchable' => false,
            ],
        ],
    ],
];
