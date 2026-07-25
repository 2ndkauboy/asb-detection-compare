-- Bundled comment corpus for the asb-detection-compare GitHub Action.
--
-- A small, synthetic, PII-free set of comments used to compare how two Antispam
-- Bee builds classify the same input. It only needs the columns driver.php
-- reads (see lib/driver.php), imported into the dedicated `corpus` database.
-- Mixes obvious ham, obvious spam (regexp terms, BBCode links, referral URLs),
-- and a couple of linkbacks so the rule set is exercised.
--
-- Extend it with more representative cases as needed; keep it synthetic.

DROP TABLE IF EXISTS wp_comments;
CREATE TABLE wp_comments (
	comment_ID           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	comment_content      TEXT,
	comment_author       TINYTEXT,
	comment_author_email VARCHAR(100) DEFAULT '',
	comment_author_url   VARCHAR(200) DEFAULT '',
	comment_author_IP    VARCHAR(100) DEFAULT '',
	comment_agent        VARCHAR(255) DEFAULT '',
	comment_type         VARCHAR(20)  DEFAULT 'comment',
	PRIMARY KEY (comment_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS wp_commentmeta;
CREATE TABLE wp_commentmeta (
	meta_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	comment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	meta_key   VARCHAR(255) DEFAULT NULL,
	meta_value LONGTEXT,
	PRIMARY KEY (meta_id),
	KEY comment_id (comment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO wp_comments
	(comment_content, comment_author, comment_author_email, comment_author_url, comment_author_IP, comment_agent, comment_type)
VALUES
	-- Ham.
	('Thanks, this walkthrough finally made the config click for me.', 'Maria', 'maria@example.com', '', '203.0.113.10', 'Mozilla/5.0 (X11; Linux x86_64)', 'comment'),
	('Does this also work with the multisite setup? Great write-up either way.', 'Jon', 'jon@example.net', 'https://jons-blog.example', '203.0.113.11', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'comment'),
	('I hit the same bug last week; clearing the object cache fixed it.', 'Priya', 'priya@example.org', '', '203.0.113.12', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)', 'comment'),
	('Bookmarked. Looking forward to the follow-up post on caching.', 'Sam', 'sam@example.com', 'https://sam.example', '203.0.113.13', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', 'comment'),
	('Small typo in step 3 (“teh”), but otherwise perfect. Cheers!', 'Lena', 'lena@example.de', '', '203.0.113.14', 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64)', 'comment'),
	('We deployed this to staging today and it just works. Thank you.', 'Diego', 'diego@example.es', '', '203.0.113.15', 'Mozilla/5.0 (Windows NT 10.0)', 'comment'),
	('Could you add a section on backups? Otherwise super clear.', 'Aisha', 'aisha@example.com', 'https://aisha.example', '203.0.113.16', 'Mozilla/5.0 (Android 14; Mobile)', 'comment'),
	('Nice. The diagram helped a lot to understand the flow.', 'Tom', 'tom@example.co', '', '203.0.113.17', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)', 'comment'),

	-- Spam: regexp-ish terms.
	('Best online CASINO bonus, play now and win real money fast!!!', 'LuckyWin', 'promo@casino-bonus.example', 'http://casino-bonus.example', '198.51.100.20', 'Mozilla/5.0', 'comment'),
	('Cheap viagra and cialis without prescription, discount pharmacy online', 'MedsDeal', 'sales@cheap-meds.example', 'http://cheap-meds.example', '198.51.100.21', 'Mozilla/5.0', 'comment'),
	('Watch free porn videos here, hot xxx content, sign up today', 'AdultSite', 'x@adult-tube.example', 'http://adult-tube.example', '198.51.100.22', 'Mozilla/5.0', 'comment'),
	('Make $5000/week working from home, join our forex trading program now', 'RichQuick', 'money@work-home.example', 'http://work-home.example', '198.51.100.23', 'Mozilla/5.0', 'comment'),

	-- Spam: BBCode links (the BBCode rule).
	('Awesome deal [url=http://buy-now.example]click here to buy[/url] limited offer', 'DealBot', 'bot@buy-now.example', 'http://buy-now.example', '198.51.100.24', 'Mozilla/5.0', 'comment'),
	('[url=http://replica-watches.example]replica watches[/url] rolex omega cheap', 'WatchGuy', 'w@replica-watches.example', 'http://replica-watches.example', '198.51.100.25', 'Mozilla/5.0', 'comment'),

	-- Spam: crypto referral (rawurl / URL rules).
	('Register on Binance and get 20% off fees: https://accounts.binance.com/register?ref=SPAM123', 'CryptoKing', 'ref@binance-promo.example', 'https://accounts.binance.com/register?ref=SPAM123', '198.51.100.26', 'Mozilla/5.0', 'comment'),
	('Free bitcoin generator, double your BTC instantly, no scam legit 2025', 'BtcDoubler', 'btc@double-coin.example', 'http://double-coin.example', '198.51.100.27', 'Mozilla/5.0', 'comment'),

	-- Borderline / short.
	('Nice post!', 'Guest', 'guest@example.com', 'http://seo-backlinks.example', '198.51.100.28', 'Mozilla/5.0', 'comment'),
	('check my site', 'Visitor', 'v@link-farm.example', 'http://link-farm.example', '198.51.100.29', 'Mozilla/5.0', 'comment'),

	-- Linkbacks (trackback/pingback).
	('An interesting related read on the same topic.', 'Related Blog', '', 'https://related-blog.example/post', '198.51.100.30', '', 'trackback'),
	('[...] as discussed in this excellent guide [...]', 'Referring Site', '', 'http://buy-now.example/casino', '198.51.100.31', '', 'pingback');
