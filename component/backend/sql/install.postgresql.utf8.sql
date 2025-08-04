/**
 * @package   contactus
 * @copyright Copyright (c)2013-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

CREATE TABLE IF NOT EXISTS "#__contactus_categories"
(
    "contactus_category_id" SERIAL,
    "title"                 varchar(255) NOT NULL DEFAULT '',
    "email"                 varchar(255)          DEFAULT '',
    "sendautoreply"         smallint              DEFAULT 0,
    "autoreply"             text,
    "access"                integer      NOT NULL DEFAULT 1,
    "language"              varchar(50)  NOT NULL DEFAULT '*',
    "ordering"              integer      NOT NULL DEFAULT 0,
    "enabled"               smallint     NOT NULL DEFAULT 0,
    "created_on"            timestamp    NULL     DEFAULT NULL,
    "created_by"            bigint       NOT NULL DEFAULT 0,
    "modified_on"           timestamp    NULL     DEFAULT NULL,
    "modified_by"           bigint       NOT NULL DEFAULT 0,
    "locked_on"             timestamp    NULL     DEFAULT NULL,
    "locked_by"             bigint       NOT NULL DEFAULT 0,
    PRIMARY KEY ("contactus_category_id")
);

CREATE TABLE IF NOT EXISTS "#__contactus_items"
(
    "contactus_item_id"     SERIAL,
    "contactus_category_id" bigint       NOT NULL,
    "fromname"              varchar(255) NOT NULL,
    "fromemail"             varchar(255) NOT NULL DEFAULT '',
    "subject"               varchar(255) NOT NULL DEFAULT '',
    "body"                  text         NOT NULL,
    "enabled"               smallint     NOT NULL DEFAULT 1,
    "token"                 char(32)              DEFAULT NULL,
    "created_on"            timestamp    NULL     DEFAULT NULL,
    "created_by"            bigint       NOT NULL DEFAULT 0,
    "modified_on"           timestamp    NULL     DEFAULT NULL,
    "modified_by"           bigint       NOT NULL DEFAULT 0,
    "locked_on"             timestamp    NULL     DEFAULT NULL,
    "locked_by"             bigint       NOT NULL DEFAULT 0,
    PRIMARY KEY ("contactus_item_id")
);