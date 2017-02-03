CREATE TABLE /*_*/blockedby (
	blckby_id int(5) NOT NULL PRIMARY KEY AUTO_INCREMENT,
	blckby_name varchar(255) NOT NULL,
	blckby_blocker varchar(255) NOT NULL,
	blckby_timestamp char(14) NOT NULL,
	blckby_expire char(14) NOT NULL,
	blckby_create tinyint(1) NOT NULL default '1',
	blckby_exact tinyint(1) NOT NULL default 0,
	blckby_reason tinyblob NOT NULL
)/*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/blckby_name ON /*_*/blockedby (blckby_name);
CREATE INDEX /*i*/blckby_timestamp ON /*_*/blockedby (blckby_timestamp);
CREATE INDEX /*i*/blckby_expire ON /*_*/blockedby (blckby_expire);

CREATE TABLE /*_*/stats_blockedby (
	stats_id int(8) NOT NULL PRIMARY KEY AUTO_INCREMENT,
	stats_blckby_id int(8) NOT NULL,
	stats_user varchar(255) NOT NULL,
	stats_blocker varchar(255) NOT NULL,
	stats_timestamp char(14) NOT NULL,
	stats_ip char(15) NOT NULL,
	stats_match varchar(255) NOT NULL default '',
	stats_dbname varchar(255) NOT NULL default ''
)/*$wgDBTableOptions*/;

CREATE INDEX /*i*/stats_blckby_id_key ON /*_*/stats_blockedby (stats_blckby_id);
CREATE INDEX /*i*/stats_timestamp ON /*_*/stats_blockedby (stats_timestamp);