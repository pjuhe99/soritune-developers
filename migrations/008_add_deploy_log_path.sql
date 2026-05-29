-- Optional path to a project's deploy log (e.g. /root/deploy.log) for the
-- observability dashboard to tail. NULL = not configured (UI shows "미설정").
ALTER TABLE projects ADD COLUMN deploy_log_path VARCHAR(255) NULL DEFAULT NULL AFTER prod_dir;
