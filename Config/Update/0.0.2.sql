
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- facebook_feed_product_excluded
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `facebook_feed_product_excluded`;

CREATE TABLE `facebook_feed_product_excluded`
(
    `pse_id` INTEGER NOT NULL,
    `is_excluded` TINYINT(4) DEFAULT 0,
    PRIMARY KEY (`pse_id`),
    CONSTRAINT `facebook_feed_product_excluded_fk_75f231`
        FOREIGN KEY (`pse_id`)
            REFERENCES `product_sale_elements` (`id`)
            ON UPDATE RESTRICT
            ON DELETE CASCADE
) ENGINE=InnoDB;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
