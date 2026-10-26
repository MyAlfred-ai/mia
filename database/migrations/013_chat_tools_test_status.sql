ALTER TABLE chat_tools ADD COLUMN last_test_ok      TINYINT(1)   DEFAULT NULL;
ALTER TABLE chat_tools ADD COLUMN last_test_message TEXT         DEFAULT NULL;
ALTER TABLE chat_tools ADD COLUMN last_tested_at    DATETIME     DEFAULT NULL;
