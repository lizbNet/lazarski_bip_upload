<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem',
        'label' => 'original_filename',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'hideTable' => true,
        'rootLevel' => -1,
        'searchFields' => 'original_filename',
    ],
    'types' => [
        '1' => ['showitem' => 'document_set, original_filename, file_extension, mime_type, size, stored_path, converted_path, status, error_message, suggested_title, title_confidence, title_source, approved_title, approved_description, final_file'],
    ],
    'columns' => [
        'document_set' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem.document_set',
            'config' => [
                'type' => 'number',
                'format' => 'integer',
                'default' => 0,
            ],
        ],
        'original_filename' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem.original_filename',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
        'file_extension' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem.file_extension',
            'config' => [
                'type' => 'input',
                'size' => 10,
                'eval' => 'trim,lower',
            ],
        ],
        'mime_type' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem.mime_type',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
            ],
        ],
        'size' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem.size',
            'config' => [
                'type' => 'number',
                'format' => 'integer',
                'default' => 0,
            ],
        ],
        'stored_path' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem.stored_path',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
        'converted_path' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem.converted_path',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
        'status' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem.status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Uploaded', 'value' => 0],
                    ['label' => 'Converted', 'value' => 1],
                    ['label' => 'Failed', 'value' => 2],
                    ['label' => 'Ready', 'value' => 3],
                    ['label' => 'Published', 'value' => 4],
                ],
                'default' => 0,
            ],
        ],
        'error_message' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem.error_message',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 4,
            ],
        ],
        'suggested_title' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem.suggested_title',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
        'title_confidence' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem.title_confidence',
            'config' => [
                'type' => 'number',
                'format' => 'integer',
                'default' => 0,
                'readOnly' => true,
            ],
        ],
        'title_source' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem.title_source',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
        'approved_title' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem.approved_title',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
        'approved_description' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem.approved_description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
            ],
        ],
        'final_file' => [
            'label' => 'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_db.xlf:tx_lazarskibipupload_domain_model_documentitem.final_file',
            'config' => [
                'type' => 'number',
                'format' => 'integer',
                'default' => 0,
                'readOnly' => true,
            ],
        ],
    ],
];
