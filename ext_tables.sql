#
# Table structure for table 'tx_lazarskibipupload_domain_model_documentset'
#
CREATE TABLE tx_lazarskibipupload_domain_model_documentset (
    staging_token varchar(32) DEFAULT '' NOT NULL,
    status tinyint(4) unsigned DEFAULT '0' NOT NULL,
    expires_at int(11) unsigned DEFAULT '0' NOT NULL,
    confirmed_page int(11) unsigned DEFAULT '0' NOT NULL,
    suggested_type varchar(32) DEFAULT '' NOT NULL,
    type_confidence smallint(5) unsigned DEFAULT '0' NOT NULL,
    suggested_page_title varchar(1024) DEFAULT '' NOT NULL,
    suggested_subtitle text,
    suggested_slug varchar(2048) DEFAULT '' NOT NULL,
    analysis_payload text,
    approved_type varchar(32) DEFAULT '' NOT NULL,
    approved_page_title varchar(1024) DEFAULT '' NOT NULL,
    approved_subtitle text,
    approved_slug varchar(2048) DEFAULT '' NOT NULL,
    approved_parent_page int(11) unsigned DEFAULT '0' NOT NULL,
    approved_fal_folder varchar(512) DEFAULT '' NOT NULL,
    suggested_auto_folder varchar(64) DEFAULT '' NOT NULL,
    include_auto_folder tinyint(1) unsigned DEFAULT '1' NOT NULL,
    approved_file_prefix varchar(255) DEFAULT '' NOT NULL,
    approved_author varchar(255) DEFAULT '' NOT NULL,

    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,
    cruser_id int(11) DEFAULT '0' NOT NULL,
    deleted tinyint(4) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY status (status),
    KEY cruser (cruser_id),
    KEY staging_token (staging_token)
);

#
# Table structure for table 'tx_lazarskibipupload_domain_model_documentitem'
#
CREATE TABLE tx_lazarskibipupload_domain_model_documentitem (
    document_set int(11) unsigned DEFAULT '0' NOT NULL,
    original_filename varchar(255) DEFAULT '' NOT NULL,
    file_extension varchar(10) DEFAULT '' NOT NULL,
    mime_type varchar(127) DEFAULT '' NOT NULL,
    size int(11) unsigned DEFAULT '0' NOT NULL,
    stored_path varchar(512) DEFAULT '' NOT NULL,
    converted_path varchar(512) DEFAULT '' NOT NULL,
    status tinyint(4) unsigned DEFAULT '0' NOT NULL,
    error_message text,
    suggested_title varchar(1024) DEFAULT '' NOT NULL,
    title_confidence smallint(5) unsigned DEFAULT '0' NOT NULL,
    title_source varchar(32) DEFAULT '' NOT NULL,
    approved_title varchar(1024) DEFAULT '' NOT NULL,
    approved_description text,
    final_file int(11) unsigned DEFAULT '0' NOT NULL,

    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,
    deleted tinyint(4) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY document_set (document_set)
);
