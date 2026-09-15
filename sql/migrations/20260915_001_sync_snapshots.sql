CREATE TABLE IF NOT EXISTS sync_snapshots (
    id CHAR(32) PRIMARY KEY,
    client_id INT NOT NULL,
    shop_id INT NOT NULL,
    high_water BIGINT NOT NULL,
    row_count INT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    INDEX (client_id, expires_at),
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (shop_id) REFERENCES shops(id)
);

CREATE TABLE IF NOT EXISTS sync_snapshot_rows (
    snapshot_id CHAR(32) NOT NULL,
    change_id BIGINT NOT NULL,
    PRIMARY KEY (snapshot_id, change_id),
    FOREIGN KEY (snapshot_id) REFERENCES sync_snapshots(id) ON DELETE CASCADE,
    FOREIGN KEY (change_id) REFERENCES sync_changes(id)
);
