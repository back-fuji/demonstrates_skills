// S2: スパイク(朝礼後の一斉利用)。10秒で0→100VUへ急増し1分維持(docs/06 §3)。
// 観察点: キャッシュ期限切れと重なったときのDB/API殺到(スタンピード)。
import http from 'k6/http';
import { check } from 'k6';
import { randomQuestion } from './questions.js';

const BASE = __ENV.BASE_URL || 'http://app:8000';

export const options = {
  scenarios: {
    spike: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '10s', target: 100 },
        { duration: '1m', target: 100 },
        { duration: '5s', target: 0 },
      ],
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
  },
};

export default function () {
  const q = randomQuestion();
  const res = http.post(`${BASE}/api/_loadtest/search`, JSON.stringify({ question: q }), {
    headers: { 'Content-Type': 'application/json' },
  });
  check(res, { 'status 200': (r) => r.status === 200 });
}
