import { Form, Link, useLoaderData } from "react-router";

import { api, type Paginated } from "~/lib/api";
import type { Route } from "./+types/logs";

export const meta: Route.MetaFunction = () => [{ title: "호출 로그 | Jarvis" }];

type ToolCallLog = {
  id: number;
  tool_name: string;
  arguments: Record<string, unknown>;
  status: string;
  error: string | null;
  duration_ms: number | null;
  created_at: string;
  source: "jarvis" | "briefing";
};

const STATUSES: Record<string, { label: string; className: string }> = {
  success: { label: "성공", className: "bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300" },
  error: { label: "오류", className: "bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300" },
  rejected: { label: "거절됨", className: "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300" },
  pending: { label: "확인 대기", className: "bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300" },
  running: { label: "실행 중", className: "bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300" },
  cancelled: { label: "취소", className: "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300" },
};

export async function clientLoader({ request }: Route.ClientLoaderArgs) {
  const url = new URL(request.url);
  const status = url.searchParams.get("status") ?? "";
  const rawSource = url.searchParams.get("source");
  // 기본은 자비스 대화에서 일어난 호출만. 대시보드 브리핑이 남기는 조회 기록은 따로 고를 때만 보인다.
  const source = rawSource === "all" || rawSource === "briefing" ? rawSource : "jarvis";
  const page = url.searchParams.get("page") ?? "1";

  const query = new URLSearchParams({ page, source });
  if (status) query.set("status", status);

  return { logs: await api<Paginated<ToolCallLog>>(`/jarvis/calls?${query}`), status, source };
}

export default function Logs() {
  const { logs, status, source } = useLoaderData<typeof clientLoader>();

  const pageLink = (page: number) =>
    `/logs?${new URLSearchParams({ ...(status ? { status } : {}), ...(source !== "jarvis" ? { source } : {}), page: String(page) })}`;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-white">호출 로그</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">자비스가 내 서비스에서 실행하거나 실행하려 했던 작업의 기록이에요.</p>
      </div>

      <Form method="get" className="flex items-center gap-2">
        <select
          name="status"
          defaultValue={status}
          onChange={(e) => e.currentTarget.form?.requestSubmit()}
          className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
        >
          <option value="">전체 상태</option>
          {Object.entries(STATUSES).map(([value, s]) => (
            <option key={value} value={value}>
              {s.label}
            </option>
          ))}
        </select>
        <select
          name="source"
          defaultValue={source}
          onChange={(e) => e.currentTarget.form?.requestSubmit()}
          className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
          aria-label="호출 출처"
        >
          <option value="jarvis">자비스 대화</option>
          <option value="briefing">대시보드 브리핑</option>
          <option value="all">전체</option>
        </select>
        <span className="text-xs text-slate-400">총 {logs.total}건</span>
      </Form>

      {logs.data.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
          기록이 없어요. 자비스와 대화하면 여기에 쌓여요.
        </div>
      ) : (
        <ul className="space-y-3">
          {logs.data.map((log) => {
            const s = STATUSES[log.status] ?? { label: log.status, className: "bg-slate-100 text-slate-600" };
            return (
              <li key={log.id} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <span className="font-mono text-sm font-medium text-slate-900 dark:text-white">{log.tool_name}</span>
                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${s.className}`}>{s.label}</span>
                    {log.source === "briefing" && (
                      <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">브리핑</span>
                    )}
                  </div>
                  <span className="text-xs text-slate-400">
                    {new Date(log.created_at).toLocaleString("ko-KR")}
                    {log.duration_ms !== null && ` · ${log.duration_ms}ms`}
                  </span>
                </div>
                <pre className="mt-2 overflow-x-auto rounded-lg bg-slate-50 p-2 text-xs text-slate-700 dark:bg-slate-950 dark:text-slate-300">
                  {JSON.stringify(log.arguments, null, 2)}
                </pre>
                {log.error && <p className="mt-2 text-xs text-red-600 dark:text-red-400">{log.error}</p>}
              </li>
            );
          })}
        </ul>
      )}

      {logs.last_page > 1 && (
        <nav className="flex items-center justify-between text-sm">
          {logs.current_page > 1 ? (
            <Link to={pageLink(logs.current_page - 1)} className="text-indigo-600 hover:underline dark:text-indigo-400">
              ← 이전
            </Link>
          ) : (
            <span />
          )}
          <span className="text-slate-400">
            {logs.current_page} / {logs.last_page}
          </span>
          {logs.current_page < logs.last_page ? (
            <Link to={pageLink(logs.current_page + 1)} className="text-indigo-600 hover:underline dark:text-indigo-400">
              다음 →
            </Link>
          ) : (
            <span />
          )}
        </nav>
      )}
    </div>
  );
}
