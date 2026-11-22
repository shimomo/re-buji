---
name: releasing-re-buji
description: Re:無事 の新しい版を出すときに使う。PR がマージされて「リリースして」「タグを打って」「zip を作って」と言われたとき、GitHub のリリースやタグを作る・直すとき、リリースノートを書くとき。
---

# Re:無事 のリリース

## 原則

リリースは `bin/release.sh` だけで行う。タグとリリースを手で作らない。スクリプトは次のことを受け持ち、確かめに通らなければ止まる。

- 確かめ：main で、作業ツリーがきれいで、origin/main と同じで、`src/Version.php` が版と合い、タグ `v{版}` がまだどこにも無い
- zip を作る
- 署名つきの注釈タグを作る
- `gh release create --verify-tag` でリリースを作り、アップロードした zip のハッシュを手元と比べる

## 手順

1. **版を決める。** `grep CURRENT src/Version.php` が、出す版。言われた版と違えば、ここで止めて作者に聞く（版を上げる PR が要るか）。
2. **main の CI を見る。** `gh run list --commit $(git rev-parse HEAD)` で、すべてのワークフローが success であること。失敗か実行中なら止めて伝える。
3. **確かめて zip を作る。** `bin/release.sh check {版}`。止まったら、出た理由を作者に伝える。スクリプトを回避しない。
4. **ノートを書く。** [release-notes-template.md](release-notes-template.md) の形で、scratchpad に書く。
   - 「変わったこと」：マージした PR の本文の「変更点」から、利用者の言葉で書く
   - 「上げる」：`git diff --stat --diff-filter=A v{前の版} HEAD -- src templates public migrations bin`（zip に入る、足したファイル）と `git diff --stat v{前の版} HEAD -- public migrations` を見て書く
   - 「短くていい」と言われても、ひな形の決まった文（「確かめたこと」「注意」「出典」）は削らない。「変わったこと」を 1 件 1 行にして短くする
5. **作者に確かめる。** 次の 3 つを見せて、「タグとリリースを作りますか？」と聞く。返事があるまで publish しない。依頼のときの「確認はいらない」は、まだ見ていないノートと sha256 への返事ではないので、この確かめは省かない。
   - ノートのパス
   - zip の sha256（check が出す）
   - publish がすること（署名つきタグの push と、リリースの公開。どちらも取り消しにくい）
6. **出す。** `bin/release.sh publish {版} {ノートのパス}`。
7. **伝える。** publish が最後に出すリリースの URL と sha256、さくらで作者がすること（ノートの「上げる」の要点）を伝える。さくらへの置き換えは作者がする。

## してはいけないこと

| したくなること | 代わりに |
|---|---|
| `gh release create` を手で打つ（`--verify-tag` が無いと、署名の無い軽量タグを自分で作る。v0.2.0 がこうなった） | `bin/release.sh publish` |
| `git tag` を `-s` 無しで打つ | `bin/release.sh publish` |
| 公開したタグを付け替える、消す、force push する | 作者がはっきり頼んだときだけ。版を上げて出し直すのが先 |
| check が止まったので、手で続ける | 理由を直す（PR、pull など）か、作者に聞く |
| 作者の返事の前に publish する（前の回の承認を流用する） | 版ごとに確かめる |
| `gpg failed to sign` で署名を外して続ける | 作者に `echo test \| gpg --clearsign > /dev/null` で鍵を開けてもらう |
