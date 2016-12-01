CREATE TABLE /*_*/blockedby (
	blckby_id int(5) NOT NULL AUTO_INCREMENT PRIMARY KEY,
	blckby_name varchar(255) NOT NULL,
	blckby_blocker varchar(255) NOT NULL,
	blckby_timestamp char(14) NOT NULL,
	blckby_expire char(14) NOT NULL,
	blckby_create tinyint(1) NOT NULL default '1',
	blckby_exact tinyint(1) NOT NULL default 0,
	blckby_reason tinyblob NOT NULL,
	UNIQUE KEY blckby_name (blckby_name),
	KEY blckby_timestamp (blckby_timestamp),
	KEY blckby_expire (blckby_expire)
)/*$wgDBTableOptions*/;

CREATE TABLE /*_*/stats_blockedby (
	stats_id int(8) NOT NULL AUTO_INCREMENT PRIMARY KEY,
	stats_blckby_id int(8) NOT NULL,
	stats_user varchar(255) NOT NULL,
	stats_blocker varchar(255) NOT NULL,
	stats_timestamp char(14) NOT NULL,
	stats_ip char(15) NOT NULL,
	stats_match varchar(255) NOT NULL default '',
	stats_dbname varchar(255) NOT NULL default '',
	KEY stats_blckby_id_key (stats_blckby_id),
	KEY stats_timestamp (stats_timestamp)
)/*$wgDBTableOptions*/;
