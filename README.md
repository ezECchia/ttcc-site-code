# TTCC Site Code

## 對應站台
- 網站： https://www.ttcc.org.tw/
- 正式站路徑： `/volume/htdocs/ttccf`
- Repo 路徑： `/volume/repos/ttcc-site-code`
- GitHub Repo： `git@github.com:ezECchia/ttcc-site-code.git`

## 目前納管範圍
本 repo 目前只納管自家開發/維護程式碼：

- `wp-content/themes/astra-child`
- `wp-content/plugins/ezec-order-payment-group-filter`

## 目前不納管項目
以下項目不納入此 repo：

- 第三方 themes
- 第三方 plugins
- `wp-content/uploads`
- `wp-content/cache`
- `wp-content/upgrade`
- `wp-content/upgrade-temp-backup`
- `wp-content/debug.log*`
- `wp-content/object-cache.php`
- `wp-content/advanced-cache.php`
- `wp-config.php`
- SQL 備份、壓縮檔、bak/old/tmp 等暫存或備份檔

## 日後回填流程
當 production 有修改後，先同步回 repo，再 commit / push：

1. 同步自家程式碼回 repo
   - `rsync -av /volume/htdocs/ttccf/wp-content/themes/astra-child/ /volume/repos/ttcc-site-code/wp-content/themes/astra-child/`
   - `rsync -av /volume/htdocs/ttccf/wp-content/plugins/ezec-order-payment-group-filter/ /volume/repos/ttcc-site-code/wp-content/plugins/ezec-order-payment-group-filter/`

2. 進入 repo
   - `cd /volume/repos/ttcc-site-code`

3. 檢查與提交
   - `git status`
   - `git add .`
   - `git commit -m "Describe your change"`
   - `git push origin main`

## 備註
- 目前 `wp-content/themes/ttccf` 與 `wp-content/plugins/ttccf` 尚未納入版控。
- 若後續確認也是自家維護程式碼，可再評估補入納管範圍。