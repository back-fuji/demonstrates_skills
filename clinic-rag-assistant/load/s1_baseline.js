// S1: 通常負荷(ベースライン)。40VU が思考時間3〜10秒で検索、5分間(docs/06 §3)。
// 全体レイテンシと検索のみレイテンシの両方を計測する。
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';
import { randomQuestion } from './questions.js';

const BASE = __ENV.BASE_URL || 'http://app:8000';
const DURATION = __ENV.DURATION || '5m';
const VUS = Number(__ENV.VUS || 40);

const searchLatency = new Trend('rag_search_full_ms');
const retrieveLatency = new Trend('rag_retrieve_only_ms');

export const options = {
  scenarios: {
    normal: { executor: 'constant-vus', vus: VUS, duration: DURATION },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    rag_search_full_ms: ['p(95)<3000'],   // NFR: 全体 p95 < 3000ms
    rag_retrieve_only_ms: ['p(95)<200'],  // NFR: 検索のみ p95 < 200ms
  },
};

export default function () {
  const q = randomQuestion();

  const full = http.post(`${BASE}/api/_loadtest/search`, JSON.stringify({ question: q }), {
    headers: { 'Content-Type': 'application/json' },
  });
  check(full, { 'search 200': (r) => r.status === 200 });
  searchLatency.add(full.timings.duration);

  const ret = http.post(`${BASE}/api/_loadtest/retrieve`, JSON.stringify({ question: q }), {
    headers: { 'Content-Type': 'application/json' },
  });
  check(ret, { 'retrieve 200': (r) => r.status === 200 });
  retrieveLatency.add(ret.timings.duration);

  sleep(3 + Math.random() * 7); // 思考時間 3〜10秒
}
