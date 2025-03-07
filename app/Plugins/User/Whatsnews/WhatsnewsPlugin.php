<?php

namespace App\Plugins\User\Whatsnews;

use Illuminate\Http\Request;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

use App\Models\Common\Buckets;
use App\Models\Common\Frame;
use App\Models\Common\Page;
use App\Models\Core\Configs;
use App\Models\Core\FrameConfig;
use App\Models\User\Whatsnews\Whatsnews;

use App\Enums\WhatsnewFrameConfig;

use App\Plugins\User\UserPluginBase;
use App\Traits\ConnectCommonTrait;

/**
 * 新着情報・プラグイン
 *
 * サイト内の新着情報を表示するプラグイン。
 *
 * @author 永原　篤 <nagahara@opensource-workshop.jp>
 * @copyright OpenSource-WorkShop Co.,Ltd. All Rights Reserved
 * @category 新着情報プラグイン
 * @package Controller
 * @plugin_title 新着情報
 * @plugin_desc 新着情報を作成できるプラグインです。サイト内のプラグインから新着記事を集めます。
 */
class WhatsnewsPlugin extends UserPluginBase
{
    use ConnectCommonTrait;

    /* オブジェクト変数 */

    /**
     * POST チェックに使用する getPost() 関数を使うか
     */
    public $use_getpost = false;

    /**
     *  新着の検索結果
     */
    public $whatsnews_results = null;

    /**
     *  新着の総件数
     */

    public $whatsnews_total_count = 0;

    /**
     *  新着のフレーム情報
     */
    public $whatsnews_frame = null;


    /* コアから呼び出す関数 */

    /**
     *  関数定義（コアから呼び出す）
     */
    public function getPublicFunctions()
    {
        // 標準関数以外で画面などから呼ばれる関数の定義
        $functions = array();
        $functions['get']  = [
            'indexJson'
        ];
        $functions['post'] = [];
        return $functions;
    }

    /**
     * 追加の権限定義（コアから呼び出す）
     */
    public function declareRole()
    {
        // 標準権限以外で設定画面などから呼ばれる権限の定義
        // 標準権限は右記で定義 config/cc_role.php
        //
        // 権限チェックテーブル (追加チェックなし)
        $role_check_table = [];
        return $role_check_table;
    }

    /**
     *  編集画面の最初のタブ（コアから呼び出す）
     *
     *  スーパークラスをオーバーライド
     */
    public function getFirstFrameEditAction()
    {
        return "editBuckets";
    }

    /* private関数 */

    /**
     *  紐づく新着情報とフレームデータの取得
     */
    private function getWhatsnewsFrame($frame_id)
    {
        // 1回呼ばれている場合
        if ($this->whatsnews_frame) {
            return $this->whatsnews_frame;
        }

        // Frame データ
        $this->whatsnews_frame = DB::table('frames')
                 ->select(
                     'frames.*',
                     'whatsnews.id as whatsnews_id',
                     'whatsnews.whatsnew_name',
                     'whatsnews.view_pattern',
                     'whatsnews.count',
                     'whatsnews.days',
                     'whatsnews.rss',
                     'whatsnews.rss_count',
                     'whatsnews.page_method',
                     'whatsnews.page_count',
                     'whatsnews.view_posted_name',
                     'whatsnews.view_posted_at',
                     'whatsnews.important',
                     'whatsnews.read_more_use_flag',
                     'whatsnews.read_more_name',
                     'whatsnews.read_more_fetch_count',
                     'whatsnews.read_more_btn_color_type',
                     'whatsnews.read_more_btn_type',
                     'whatsnews.read_more_btn_transparent_flag',
                     'whatsnews.target_plugins',
                     'whatsnews.frame_select',
                     'whatsnews.target_frame_ids'
                 )
                 ->leftJoin('whatsnews', 'whatsnews.bucket_id', '=', 'frames.bucket_id')
                 ->where('frames.id', $frame_id)
                 ->first();
        return $this->whatsnews_frame;
    }

    /**
     *  新着対象のプラグインがあるフレームデータの取得
     */
    private function getTargetPluginsFrames()
    {
        // debug:確認したいSQLの前にこれを仕込んで
        //DB::enableQueryLog();

        // Frame データ
        $frames = Frame::select('frames.*', 'pages._lft', 'pages.page_name', 'buckets.bucket_name')
                        ->whereIn('frames.plugin_name', array('blogs', 'bbses', 'databases'))
                        ->leftJoin('buckets', 'frames.bucket_id', '=', 'buckets.id')
                        ->leftJoin('pages', 'frames.page_id', '=', 'pages.id')
                        ->where('disable_whatsnews', 0)
                        ->orderBy('pages._lft', 'asc')
                        ->get();

        // sql debug
        //Log::debug(var_export(DB::getQueryLog(), true));
        return $frames;
    }

    /**
     * 表示記事の件数取得
     */
    public function getContentsCount($frame_id)
    {
        // フレームから、新着の設定取得
        $whatsnews_frame = $this->getWhatsnewsFrame($frame_id);

        // 新着の一覧取得
        list($whatsnews, $link_pattern, $link_base) = $this->getWhatsnews($whatsnews_frame);

        // 件数を返却
        return $whatsnews ? count($whatsnews) : 0;
    }

    /**
     * 新着記事の取得
     */
    private function getWhatsnews($whatsnews_frame, $method = null)
    {
        // DB::enableQueryLog();
        // 新着情報がまだできていない場合
        if (!$whatsnews_frame || empty($whatsnews_frame->whatsnews_id)) {
            return array(null, null, null);
        }

        // 1回呼ばれている場合
        if ($this->whatsnews_results) {
            return $this->whatsnews_results;
        }

        // ターゲットプラグインをループ
        $target_plugins = explode(',', $whatsnews_frame->target_plugins);

        // ターゲットが指定されていない場合は空を返す。
        if (empty(array_filter($target_plugins))) {
            return [null, null, null];
        }

        // union するSQL を各プラグインから取得。その際に使用するURL パターンとベースのURL も取得
        $union_sqls = array();
        $link_pattern = array();
        $link_base = array();
        foreach ($target_plugins as $target_plugin) {
            // クラスファイルの存在チェック。
            $file_path = base_path() . "/app/Plugins/User/" . ucfirst($target_plugin) . "/" . ucfirst($target_plugin) . "Plugin.php";

            // ファイルの存在確認
            if (!file_exists($file_path)) {
                return $this->viewError("500_inframe", null, 'ファイル Not found.<br />' . $file_path);
            }

            // 各プラグインのgetWhatsnewArgs() 関数を呼び出し。
            $class_name = "App\Plugins\User\\" . ucfirst($target_plugin) . "\\" . ucfirst($target_plugin) . "Plugin";

            list($union_sqls[$target_plugin], $link_pattern[$target_plugin], $link_base[$target_plugin]) = $class_name::getWhatsnewArgs();
        }

        $whatsnews = $this->buildQueryGetWhatsnews($whatsnews_frame, $union_sqls)->get();
        // Log::debug(DB::getQueryLog());
        // Log::debug($whatsnews);

        // bugfix: 新着タイトルにウィジウィグが入る事がある（databaseのウィジウィグ型をタイトルに指定）ため、タグ除去する。
        $whatsnews->transform(function ($whatsnew, $key) {
            $whatsnew->post_title = strip_tags($whatsnew->post_title);
            return $whatsnew;
        });

        // 取得後の絞り込み

        // 重要なもののみ
        if ($whatsnews_frame->important == 'important_only') {
            // $union_sql->where('important', 1);
            $whatsnews = $whatsnews->where('important', 1);
        }
        // 重要なものを除外
        if ($whatsnews_frame->important == 'not_important') {
            // $union_sql->whereNull('important');
            $whatsnews = $whatsnews->where('important', null);
        }

        // 新着の「もっと見る」処理判定用に総件数を保持
        $this->whatsnews_total_count = $whatsnews->count();

        // 件数制限
        if ($method == 'rss') {
            // 「RSS件数」で制限
            // $whatsnews_sql->limit($whatsnews_frame->rss_count);
            $whatsnews = $whatsnews->slice(0, $whatsnews_frame->rss_count);
        } elseif ($whatsnews_frame->view_pattern == 0) {
            // 「表示件数」で制限
            // $whatsnews_sql->limit($whatsnews_frame->count);
            $whatsnews = $whatsnews->slice(0, $whatsnews_frame->count);
        } else {
            // 「表示日数」で制限
            // $whatsnews_sql->where('posted_at', '>=', date('Y-m-d H:i:s', strtotime("- " . $whatsnews_frame->days . " day")));
            $whatsnews = $whatsnews->where('posted_at', '>=', date('Y-m-d H:i:s', strtotime("- " . $whatsnews_frame->days . " day")));
        }

        // 記事詳細から、最初の画像を抜き出して設定する。
        $whatsnews = $this->addWhatsnewsValue($whatsnews);

        // 一旦オブジェクト変数へ。（Singleton のため。フレーム表示確認でコアが使用する）
        $this->whatsnews_results = array($whatsnews, $link_pattern, $link_base);

        return $this->whatsnews_results;
    }

    /**
     * 記事詳細から、最初の画像を抜き出して設定する。
     * 記事詳細に、追加情報を設定する。
     */
//    private function addFirstImage($whatsnews)
    private function addWhatsnewsValue($whatsnews, $post_detail_length = null)
    {
        // 記事詳細から、最初の画像を抜き出し
        $pattern_img = '/<img.*?src\s*=\s*[\"|\'](.*?)[\"|\'].*?>/i';
        $pattern_alt = '/(alt)=("[^"]*")/i';
        foreach ($whatsnews as &$whatsnew) {
            // 画像があるときはファイルパスを抽出
            preg_match($pattern_img, $whatsnew->post_detail, $images);
            if (is_array($images) && count($images) > 1) {
                $whatsnew->first_image_path = $images[1];
                // altがあるときはaltを抽出
                preg_match($pattern_alt, $images[0], $alts);
                if (is_array($alts) && count($alts) > 2) {
                    $whatsnew->first_image_alt = $alts[2];
                } else {
                    $whatsnew->first_image_alt = null;
                }
            } else {
                $whatsnew->first_image_path = null;
                $whatsnew->first_image_alt = null;
            }

            // タイトルのタグを取り除き, データベース、ウィジウィグ型のタイトル指定に対応
            $whatsnew->post_title_strip_tags = strip_tags($whatsnew->post_title);

            // タグを取り除き、指定に応じて文字数制限した本文
            if ($post_detail_length) {
                $whatsnew->post_detail_strip_tags = mb_substr(strip_tags($whatsnew->post_detail), 0, $post_detail_length);
                if (mb_strlen(strip_tags($whatsnew->post_detail)) > $post_detail_length) {
                    $whatsnew->post_detail_strip_tags = $whatsnew->post_detail_strip_tags . '...';
                }
            } else {
                $whatsnew->post_detail_strip_tags = strip_tags($whatsnew->post_detail);
            }
        }

        return $whatsnews;
    }

    private function buildQueryGetWhatsnews($whatsnews_frame, $union_sqls)
    {
        // ベースの新着DUAL（ダミーテーブル）
        $whatsnews_sql = DB::table('whatsnews_dual')
            ->select(
                'page_id',
                'frame_id',
                'post_id',
                'post_title',
                'post_detail',
                DB::raw("null as important"),
                'posted_at',
                DB::raw("null as posted_name"),
                'categories.classname        as classname',
                'categories.category         as category',
                DB::raw("null as plugin_name")
            )
            ->leftJoin('categories', 'categories.id', '=', 'whatsnews_dual.categories_id');

        // 各プラグインのSQLにwhere条件を付加してUNION
        foreach ($union_sqls as $union_sql) {
            // （where条件）フレーム選択
            if ($whatsnews_frame->frame_select == 1) {
                $union_sql->whereIn('frames.id', explode(',', $whatsnews_frame->target_frame_ids));
            }

            // 不要な新着の除外
            $union_whatsnews = $this->excludeOfUnnecessary($union_sql->get());
            $union_sql->whereIn('frames.id', $union_whatsnews->pluck('frame_id'));

            // UNION
            $whatsnews_sql->unionAll($union_sql);
        }

        if ($whatsnews_frame->important == 'top') {
            // （orderBy条件）重要記事の扱い
            $whatsnews_sql->orderBy('important', 'desc');
        }
        // （orderBy条件）デフォルトは登録日時の降順
        $whatsnews_sql->orderBy('posted_at', 'desc');

        return $whatsnews_sql;
    }

    /**
     * 不要な新着の除外（記事重複・見えないページ・フレーム）
     */
    private function excludeOfUnnecessary(Collection $whatsnews)
    {
        // 削除ページ除外
        $pages = Page::whereIn('id', $whatsnews->pluck('page_id')->unique())->get();
        $whatsnews = $whatsnews->whereIn('page_id', $pages->pluck('id'));

        // 見れないページ除外
        $visible_page_ids = [];
        $request = app(Request::class);
        foreach ($pages as $page) {
            // 自分のページから親を遡って取得
            $page_tree = Page::reversed()->ancestorsAndSelf($page->id);

            // パスワード認証
            if ($page->isRequestPassword($request, $page_tree)) {
                // 見れないページ
                continue;
            }

            // 親子ページを加味してページ表示できるか
            if (!$page->isVisibleAncestorsAndSelf($page_tree)) {
                continue;
            }

            // 見れるページ
            $visible_page_ids[] = $page->id;
        }
        $whatsnews = $whatsnews->whereIn('page_id', $visible_page_ids);

        // 非公開フレーム除外
        $visible_frame_ids = [];
        $frames = Frame::whereIn('id', $whatsnews->pluck('frame_id')->unique())->get();
        foreach ($frames as $frame) {
            // 非公開・限定公開フレームが非表示か
            if ($frame->isInvisiblePrivateFrame()) {
                // 見れないフレーム
                continue;
            }

            // 見れるフレーム
            $visible_frame_ids[] = $frame->id;
        }
        $whatsnews = $whatsnews->whereIn('frame_id', $visible_frame_ids);

        // 重複排除
        $whatsnews = $whatsnews->unique('post_id');

        return $whatsnews;
    }

    /**
     *  新着一覧をJSON形式で返す
     */
    public function indexJson($request, $page_id, $frame_id)
    {
        // フレームから新着の設定取得
        $whatsnews_frame = $this->getWhatsnewsFrame($frame_id);
        // 新着情報がまだできていない場合
        if (!$whatsnews_frame || empty($whatsnews_frame->whatsnews_id)) {
            return "error";
        }

        $target_plugins = explode(',', $whatsnews_frame->target_plugins);

        // union するSQL を各プラグインから取得。その際に使用するURL パターンとベースのURL も取得
        $union_sqls = array();
        $link_pattern = array();
        $link_base = array();
        /**
         * ターゲットプラグインをループして下記を取得 ※一部の情報はここでは未使用
         *  - unionする各プラグインの抽出SQL
         *  - リンクのURL ※未使用
         *  - ベースのURL ※未使用
         */
        foreach ($target_plugins as $target_plugin) {
            // クラスファイルの存在チェック。
            $file_path = base_path() . "/app/Plugins/User/" . ucfirst($target_plugin) . "/" . ucfirst($target_plugin) . "Plugin.php";

            // ファイルの存在確認
            if (!file_exists($file_path)) {
                return $this->viewError("500_inframe", null, 'ファイル Not found.<br />' . $file_path);
            }

            // 各プラグインのgetWhatsnewArgs() 関数を呼び出し。
            $class_name = "App\Plugins\User\\" . ucfirst($target_plugin) . "\\" . ucfirst($target_plugin) . "Plugin";

            list($union_sqls[$target_plugin], $link_pattern[$target_plugin], $link_base[$target_plugin]) = $class_name::getWhatsnewArgs();
        }

        // クエリ取得
        $whatsnews_query = $this->buildQueryGetWhatsnews($whatsnews_frame, $union_sqls);

        // limit/offset条件を付加
        if ($request->limit) {
            $whatsnews_query->limit($request->limit);
        }
        if ($request->offset) {
            $whatsnews_query->offset($request->offset);
        }
        // データ抽出
        $whatsnewses = $whatsnews_query->get();

        // bugfix: 新着タイトルにウィジウィグが入る事がある（databaseのウィジウィグ型をタイトルに指定）ため、タグ除去する。
        $whatsnewses->transform(function ($whatsnew, $key) {
            $whatsnew->post_title = strip_tags($whatsnew->post_title);
            return $whatsnew;
        });

        // 記事詳細から、最初の画像を抜き出して設定する。
        $whatsnewses = $this->addWhatsnewsValue($whatsnewses, $request->post_detail_length);

        // 整形して返却
        return json_encode(json_decode($whatsnewses), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * プラグインのバケツ取得関数
     */
    public function getPluginBucket($bucket_id)
    {
        // プラグインのメインデータを取得する。
        return Whatsnews::firstOrNew(['bucket_id' => $bucket_id]);
    }

    /* 画面アクション関数 */

    /**
     * データ初期表示関数
     * コアがページ表示の際に呼び出す関数
     *
     * @method_title 新着一覧
     * @method_desc サイト内の新着を表示します。
     * @method_detail タイトルのみ、本文やサムネイルも表示など、設定画面で条件を設定できます。。
     */
    public function index($request, $page_id, $frame_id)
    {
        // フレームから、新着の設定取得
        $whatsnews_frame = $this->getWhatsnewsFrame($frame_id);

        // 新着の一覧取得
        list($whatsnews, $link_pattern, $link_base) = $this->getWhatsnews($whatsnews_frame);
        // Log::debug(var_export($whatsnews, true));
        // Log::debug(var_export($link_pattern, true));

        // 表示テンプレートを呼び出す。
        return $this->view(
            'whatsnews', [
            'whatsnews'       => $whatsnews,
            'whatsnews_frame' => $whatsnews_frame,
            'whatsnews_total_count' => $this->whatsnews_total_count,
            'link_pattern'    => $link_pattern,
            'link_base'       => $link_base,
            ]
        );
    }

    /**
     * データ選択表示関数
     *
     * @method_title 選択
     * @method_desc このフレームに表示する新着情報を選択します。
     * @method_detail
     */
    public function listBuckets($request, $page_id, $frame_id, $id = null)
    {
        // Frame データ
        $whatsnew_frame = $this->getWhatsnewsFrame($frame_id);

        // データ取得（1ページの表示件数指定）
        $whatsnews = Whatsnews::orderBy('created_at', 'desc')
                              ->paginate(10, ["*"], "frame_{$frame_id}_page");

        // 表示テンプレートを呼び出す。
        return $this->view(
            'whatsnews_list_buckets', [
            'whatsnew_frame' => $whatsnew_frame,
            'whatsnews'      => $whatsnews,
            ]
        );
    }

    /**
     * 新着情報設定の新規作成画面
     *
     * @method_title 作成
     * @method_desc 新着情報を新しく作成します。
     * @method_detail 新着情報名や表示条件を入力して新着情報を作成できます。
     */
    public function createBuckets($request, $page_id, $frame_id, $id = null, $create_flag = false, $message = null, $errors = null)
    {
        // 新規作成フラグを付けて設定変更画面を呼ぶ
        $create_flag = true;
        return $this->editBuckets($request, $page_id, $frame_id, $id, $create_flag, $message, $errors);
    }

    /**
     * 新着情報設定変更画面の表示
     */
    public function editBuckets($request, $page_id, $frame_id, $id = null, $create_flag = false, $message = null, $errors = null)
    {
        // セッション初期化などのLaravel 処理。
        $request->flash();

        // 新着情報＆フレームデータ
        $whatsnew_frame = $this->getWhatsnewsFrame($frame_id);

        // 新着情報設定データ
        $whatsnew = new Whatsnews();

        if (!empty($id)) {
            // id が渡ってくればid が対象
            $whatsnew = Whatsnews::where('id', $id)->first();
        } elseif (!empty($whatsnew_frame->bucket_id) && $create_flag == false) {
            // Frame のbucket_id があれば、bucket_id から新着情報設定データ取得、なければ、新規作成か選択へ誘導
            $whatsnew = Whatsnews::where('bucket_id', $whatsnew_frame->bucket_id)->first();
        }

        // 選択できるフレームの一覧
        $target_plugins_frames = $this->getTargetPluginsFrames();

        // 表示テンプレートを呼び出す。
        return $this->view(
            'whatsnews_edit_whatsnew', [
            'whatsnew_frame'        => $whatsnew_frame,
            'whatsnew'              => $whatsnew,
            'target_plugins_frames' => $target_plugins_frames,
            'create_flag'           => $create_flag,
            'message'               => $message,
            'errors'                => $errors,
            ]
        )->withInput($request->all);
    }

    /**
     *  新着情報設定の登録処理
     */
    public function saveBuckets($request, $page_id, $frame_id, $id = null)
    {
        // フレームから、新着の設定取得
        $whatsnews_frame = $this->getWhatsnewsFrame($frame_id);

        // 項目のエラーチェック
        $validator = Validator::make($request->all(), [
            'whatsnew_name'     => ['required'],
            'target_plugin'     => ['required'],
            'count'             => ['nullable', 'numeric'],
            'days'              => ['nullable', 'numeric'],
            'rss_count'         => ['nullable', 'numeric'],
            'read_more_use_flag' => ['required', 'numeric'],
            'read_more_name' => ['required'],
            'read_more_fetch_count' => ['required', 'numeric'],
            'read_more_btn_color_type' => ['required'],
            'read_more_btn_type' => ['required'],
            'read_more_btn_transparent_flag' => ['required', 'numeric'],
        ]);
        $validator->setAttributeNames([
            'whatsnew_name'     => '新着情報設定名称',
            'target_plugin'     => '対象プラグイン',
            'count'             => '表示件数',
            'days'              => '表示日数',
            'rss_count'         => '対象RSS件数',
            'read_more_use_flag' => 'もっと見るボタンの表示',
            'read_more_name' => 'ボタン名',
            'read_more_fetch_count' => 'ボタン押下時の取得件数／回',
            'read_more_btn_color_type' => 'もっと見るボタン色',
            'read_more_btn_type' => 'もっと見るボタンの形',
            'read_more_btn_transparent_flag' => 'ボタン透過の使用',
        ]);

        // エラーがあった場合は入力画面に戻る。
        $message = null;
        if ($validator->fails()) {
            if (empty($whatsnews_frame->whatsnews_id)) {
                $create_flag = true;
                return $this->createBuckets($request, $page_id, $frame_id, $id, $create_flag, $message, $validator->errors());
            } else {
                $create_flag = false;
                return $this->editBuckets($request, $page_id, $frame_id, $id, $create_flag, $message, $validator->errors());
            }
        }

        // 更新後のメッセージ
        $message = null;

        if (empty($request->whatsnews_id)) {
            // 画面から渡ってくるwhatsnews_id が空ならバケツと設定データを新規登録
            // バケツの登録
            $bucket_id = DB::table('buckets')->insertGetId([
                // 'bucket_name' => '無題',
                'bucket_name' => $request->whatsnew_name,
                'plugin_name' => 'whatsnews'
            ]);

            // 新着情報設定データ新規オブジェクト
            $whatsnews = new Whatsnews();
            $whatsnews->bucket_id = $bucket_id;

            // Frame のBuckets を見て、Buckets が設定されていなければ、作成したものに紐づける。
            // Frame にBuckets が設定されていない ＞ 新規のフレーム＆新着情報設定作成
            // Frame にBuckets が設定されている ＞ 既存のフレーム＆新着情報設定更新
            // （新着情報設定選択から遷移してきて、内容だけ更新して、フレームに紐づけないケースもあるため）
            $frame = Frame::where('id', $frame_id)->first();
            if (empty($frame->bucket_id)) {
                // FrameのバケツIDの更新
                $frame = Frame::where('id', $frame_id)->update(['bucket_id' => $bucket_id]);
            }

            $message = '新着情報設定を追加しました。';
        } else {
            // whatsnews_id があれば、新着情報設定を更新
            // 新着情報設定の取得
            $whatsnews = Whatsnews::where('id', $request->whatsnews_id)->first();

            // 新着情報名で、Buckets名も更新する
            Buckets::where('id', $whatsnews->bucket_id)->update(['bucket_name' => $request->whatsnew_name]);

            $message = '新着情報設定を変更しました。';
        }

        // 新着情報設定
        $whatsnews->whatsnew_name     = $request->whatsnew_name;
        $whatsnews->view_pattern      = $request->view_pattern;
        $whatsnews->count             = (intval($request->count) < 0) ? 0 : intval($request->count);
        $whatsnews->days              = (intval($request->days) < 0) ? 0 : intval($request->days);
        $whatsnews->rss               = $request->rss;
        $whatsnews->rss_count         = intval($request->rss_count);
        // $whatsnews->page_method       = $request->page_method;
        // $whatsnews->page_count        = (intval($request->page_count) < 0) ? 0 : intval($request->page_count);
        $whatsnews->view_posted_name  = $request->view_posted_name;
        $whatsnews->view_posted_at    = $request->view_posted_at;
        $whatsnews->important         = $request->important;
        $whatsnews->read_more_use_flag = $request->read_more_use_flag;
        $whatsnews->read_more_name = $request->read_more_name;
        $whatsnews->read_more_fetch_count = $request->read_more_fetch_count;
        $whatsnews->read_more_btn_color_type = $request->read_more_btn_color_type;
        $whatsnews->read_more_btn_type = $request->read_more_btn_type;
        $whatsnews->read_more_btn_transparent_flag = $request->read_more_btn_transparent_flag;
        $whatsnews->target_plugins    = implode(',', $request->target_plugin);
        $whatsnews->frame_select      = intval($request->frame_select);
//Log::debug($request->target_frame_ids);
        $whatsnews->target_frame_ids  = empty($request->target_frame_ids) ? "": implode(',', $request->target_frame_ids);

        // データ保存
        $whatsnews->save();

        // 新規作成フラグを付けて新着情報設定変更画面を呼ぶ
        $create_flag = false;
        return $this->editBuckets($request, $page_id, $frame_id, $id, $create_flag, $message);
    }

    /**
     *  削除処理
     */
    public function destroyBuckets($request, $page_id, $frame_id, $id)
    {
        // id がある場合、データを削除
        if ($id) {
            // フレームから、新着の設定取得
            $whatsnews_frame = $this->getWhatsnewsFrame($frame_id);

            // 新着設定を削除する。
            Whatsnews::where('id', $id)->delete();

            // backetsの削除
            Buckets::where('id', $whatsnews_frame->bucket_id)->delete();

            // FrameのバケツIDの更新
            Frame::where('id', $frame_id)->update(['bucket_id' => null]);
        }
        // 削除処理はredirect 付のルートで呼ばれて、処理後はページの再表示が行われるため、ここでは何もしない。
    }

    /**
     * データ紐づけ変更関数
     */
    public function changeBuckets($request, $page_id = null, $frame_id = null, $id = null)
    {
        // FrameのバケツIDの更新
        Frame::where('id', $frame_id)
               ->update(['bucket_id' => $request->select_bucket]);

        // 新着情報設定選択画面を呼ぶ
        return $this->listBuckets($request, $page_id, $frame_id, $id);
    }

    /**
     * フレーム表示設定画面の表示
     *
     * @method_title 表示設定
     * @method_desc このフレームに表示する際の新着をカスタマイズできます。
     * @method_detail 本文の文字の長さやサムネイルなどを設定して、自分のサイトにあった新着情報を表示できます。
     */
    public function editView($request, $page_id, $frame_id)
    {
        // 表示テンプレートを呼び出す。
        return $this->view('whatsnews_frame', [
            'whatsnew' => $this->getPluginBucket($this->getBucketId()),
        ]);
    }

    /**
     * フレーム表示設定の保存
     */
    public function saveView($request, $page_id, $frame_id, $cabinet_id)
    {
        // フレーム設定保存
        $this->saveFrameConfigs($request, $frame_id, WhatsnewFrameConfig::getMemberKeys());

        // 更新したので、frame_configsを設定しなおす
        $this->refreshFrameConfigs();

        return;
    }

    /**
     * フレーム設定を保存する。
     *
     * @param Illuminate\Http\Request $request リクエスト
     * @param int $frame_id フレームID
     * @param array $frame_config_names フレーム設定のname配列
     */
    protected function saveFrameConfigs(\Illuminate\Http\Request $request, int $frame_id, array $frame_config_names)
    {

        // 項目のエラーチェック
        $validator = Validator::make($request->all(), [
            'thumbnail_size'  => ['numeric'],
            'post_detail_length'  => ['numeric'],
        ]);
        $validator->setAttributeNames([
            'thumbnail_size'  => WhatsnewFrameConfig::enum['thumbnail_size'],
            'post_detail_length'  => WhatsnewFrameConfig::enum['post_detail_length'],
        ]);

        // エラーがあった場合は入力画面に戻る。
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        // フレーム設定の定数にあるものがrequest にあれば、保存する。
        foreach ($frame_config_names as $key => $value) {

            FrameConfig::updateOrCreate(
                ['frame_id' => $frame_id, 'name' => $value],
                ['value' => $request->$value]
            );
        }
    }

    /**
     *  RSS配信
     */
    public function rss($request, $page_id, $frame_id, $id = null)
    {
        // フレームから、新着の設定取得
        $whatsnews_frame = $this->getWhatsnewsFrame($frame_id);
        if (empty($whatsnews_frame)) {
            return;
        }

        // サイト名
        $base_site_name = Configs::where('name', 'base_site_name')->first();

        // URL
        $url = url("/redirect/plugin/whatsnews/rss/" . $page_id . "/" . $frame_id);

        // HTTPヘッダー出力
        header('Content-Type: text/xml; charset=UTF-8');

        echo <<<EOD
<rss xmlns:content="http://purl.org/rss/1.0/modules/content/" version="2.0">
<channel>
<title>[{$base_site_name->value}]{$whatsnews_frame->whatsnew_name}</title>
<description></description>
<link>
{$url}
</link>
EOD;

        // 新着の一覧取得
        list($whatsnews, $link_pattern, $link_base) = $this->getWhatsnews($whatsnews_frame, 'rss');

        foreach ($whatsnews as $whatsnew) {
            $title = $whatsnew->post_title;
            $link = url($link_base[$whatsnew->plugin_name] . '/' . $whatsnew->page_id . '/' . $whatsnew->frame_id . '/' . $whatsnew->post_id);
//            $description = strip_tags(mb_substr($blogs_post->post_text, 0, 20));
            $pub_date = date(DATE_RSS, strtotime($whatsnew->posted_at));
            $content = strip_tags(html_entity_decode($whatsnew->post_title));
            echo <<<EOD

<item>
<title>{$title}</title>
<link>{$link}</link>
<pubDate>{$pub_date}</pubDate>
<content:encoded>{$content}</content:encoded>
</item>
EOD;
        }

        echo <<<EOD
</channel>
</rss>
EOD;

        exit;
    }
}
