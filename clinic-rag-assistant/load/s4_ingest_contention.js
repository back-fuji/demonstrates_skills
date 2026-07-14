// S4: 取り込み競合(docs/06 §3)。同一文書への更新を多並列で送信する。
//   - 対策前(楽観ロック無効): 全て 202(後勝ち)。二重取り込み・不整合が起こりうる。
//   - 対策後(楽観ロック有効): 1件が 202・残りが 409。データ不整合ゼロ。
// document_id と expected_version は __ENV で渡す。
import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://app:8000';
const DOC_ID = Number(__ENV.DOC_ID || 1);
const VERSION = Number(__ENV.VERSION || 1);
const VUS = Number(__ENV.VUS || 10);

const accepted = new Counter('ingest_accepted_202');
const conflict = new Counter('ingest_conflict_409');

export const options = {
  scenarios: {
    contention: { executor: 'per-vu-iterations', vus: VUS, iterations: 1, maxDuration: '30s' },
  },
};

export default function () {
  const res = http.post(
    `${BASE}/api/_loadtest/update-doc`,
    JSON.stringify({ document_id: DOC_ID, expected_version: VERSION }),
    { headers: { 'Content-Type': 'application/json' } },
  );
  if (res.status === 202) accepted.add(1);
  if (res.status === 409) conflict.add(1);
  check(res, { '202 or 409': (r) => r.status === 202 || r.status === 409 });
}
