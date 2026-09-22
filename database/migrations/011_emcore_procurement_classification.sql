-- Add EMCORE-owned procurement classification master data and a numeric,
-- analytically useful estimated amount. Existing notice classification names
-- and the legacy secondary guarantee remain untouched for audit compatibility.

CREATE TABLE IF NOT EXISTS emcore_procurement_categories (
    category_id INT UNSIGNED NOT NULL,
    category_name VARCHAR(255) NOT NULL,
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_emcore_procurement_category_name (category_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_procurement_subcategories (
    subcategory_id INT UNSIGNED NOT NULL,
    subcategory_name VARCHAR(255) NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (subcategory_id),
    UNIQUE KEY uq_emcore_procurement_subcategory_parent_name
        (category_id, subcategory_name),
    KEY idx_emcore_procurement_subcategory_category (category_id),
    CONSTRAINT fk_emcore_procurement_subcategory_category
        FOREIGN KEY (category_id)
        REFERENCES emcore_procurement_categories (category_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_procurement_products (
    product_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    subcategory_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (product_id),
    UNIQUE KEY uq_emcore_procurement_product_parent_name
        (subcategory_id, product_name),
    KEY idx_emcore_procurement_product_subcategory (subcategory_id),
    CONSTRAINT fk_emcore_procurement_product_subcategory
        FOREIGN KEY (subcategory_id)
        REFERENCES emcore_procurement_subcategories (subcategory_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO emcore_procurement_categories (category_id, category_name)
VALUES
    (1, 'مواد معدنی و شیمیایی'),
    (2, 'مواد کمکی و مصرفی صنایع'),
    (3, 'سنگ آهن و مشتقات'),
    (4, 'فلزات غیر آهنی'),
    (5, 'پیشنهادی')
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name);

INSERT INTO emcore_procurement_subcategories
    (subcategory_id, subcategory_name, category_id)
VALUES
    (101, 'معدنی', 1),
    (102, 'سایر', 1),
    (201, 'مصرفی', 2),
    (202, 'کربن اکتیو', 2),
    (301, 'آهن اسفنجی', 3),
    (302, 'سنگ آهن', 3),
    (303, 'فولاد', 3),
    (401, 'فروآلیاژ', 4),
    (402, 'قلع', 4),
    (403, 'نیکل', 4),
    (404, 'مس', 4),
    (405, 'سرب', 4),
    (406, 'روی', 4),
    (407, 'تیتانیوم', 4),
    (408, 'آلومینیوم', 4)
ON DUPLICATE KEY UPDATE
    subcategory_name = VALUES(subcategory_name),
    category_id = VALUES(category_id);

INSERT INTO emcore_procurement_products
    (product_id, product_name, subcategory_id)
VALUES
    (1001, 'آهک', 101),
    (1002, 'بنتونیت', 101),
    (1003, 'باریت', 101),
    (1004, 'آپاتیت', 101),
    (1008, 'DRI', 301),
    (1009, 'کنسانتره', 302),
    (1102, 'تیتانیوم', 102),
    (1103, 'کلوخه کالامین', 102),
    (1104, 'گلوله فورچ', 201),
    (1105, 'الکترود کربنی', 201),
    (1106, 'کاتالیست', 201),
    (1107, 'بریکت سرد و گرم', 301),
    (1110, 'نرمه گندله', 302),
    (1111, 'گندله', 302),
    (1112, 'کلوخه', 302),
    (1113, 'دانه بندی', 302),
    (1114, 'شمش فولادی', 303),
    (1115, 'نورد میلگرد', 303),
    (1116, 'نورد ورق سیاه فولادی', 303),
    (1117, 'فرووانادیوم', 401),
    (1118, 'فروسیلیس', 401),
    (1119, 'فروسیلیکومنگنز', 401),
    (1120, 'فرومنگنز', 401),
    (1121, 'فروکروم', 401),
    (1122, 'فرومولیبدن', 401),
    (1123, 'کاتد نیکل', 403),
    (1124, 'نیکل سولفامات', 403),
    (1125, 'کاتد مس', 404),
    (1126, 'ورق آلومینیوم', 408),
    (1127, 'بلوک آلومینیوم', 408),
    (1128, 'فروتیتانیوم', 401),
    (1129, 'سولفات مس', 404)
ON DUPLICATE KEY UPDATE
    product_name = VALUES(product_name),
    subcategory_id = VALUES(subcategory_id);

ALTER TABLE emcore_procurement_notices
    ADD COLUMN estimated_amount DECIMAL(24,0) NULL AFTER amount_text;
