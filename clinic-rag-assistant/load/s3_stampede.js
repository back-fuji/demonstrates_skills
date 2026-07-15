// S3: キャッシュスタンピード再現(docs/06 §3)。
// 人気質問のキャッシュを強制失効させた直後に多数のVUが同一質問へ殺到する。
//   - 対策前(SWR無効): フェイクLLMへの同時リクエストがVU数ぶん発生することを確認
//   - 対策後(SWR+ロック): 生成処理が1件に収束することを確認
// 判定指標: teardown で取得する llm_generate_calls(サーバ側の実生成回数)。
import http from 'k6/http';
import { check, sleep } from 'k6';
import { POPULAR } from './questions.js';

const BASE = __ENV.BASE_URL || 'http://app:8000';
const VUS = Number(__ENV.VUS || 100);

export const options = {
  scenarios: {
    // ほぼ同時に VUS 件のリクエストを1発ずつ撃つ
    burst: { executor: 'per-vu-iterations', vus: VUS, iterations: 1, maxDuration: '60s' },
  },
};

export function setup() {
  const body = JSON.stringify({ question: POPULAR });
  const h = { headers: { 'Content-Type': 'application/json' } };
  // 1) 人気質問のキャッシュを事前生成(fresh+stale)
  http.post(`${BASE}/api/_loadtest/warm`, body, h);
  // 2) メトリクスをリセット
  http.post(`${BASE}/api/_loadtest/reset-metrics`, null);
  // 3) fresh を強制失効(SWR有効なら stale が残る)
  http.post(`${BASE}/api/_loadtest/expire-cache`, body, h);
}

export default function () {
  const res = http.post(`${BASE}/api/_loadtest/search`, JSON.stringify({ question: POPULAR }), {
    headers: { 'Content-Type': 'application/json' },
  });
  check(res, { 'status 200': (r) => r.status === 200 });
}

export function teardown() {
  // 裏の再生成ジョブが動く猶予
  sleep(4);
  const m = http.get(`${BASE}/api/_loadtest/metrics`);
  console.log(`[S3] llm_generate_calls = ${m.json('llm_generate_calls')} (VUs=${VUS})`);
}
