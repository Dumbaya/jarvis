import { useEffect, useState } from "react";
import { Link, useLoaderData, useRouteLoaderData } from "react-router";

import { api, ApiError, humanizeSeconds, sectionError, type Briefing, type Service, type Usage, type User } from "~/lib/api";
import type { Route } from "./+types/dashboard";

export const meta: Route.MetaFunction = () => [{ title: "대시보드 | Jarvis" }];

export async function clientLoader() {
  const [services, usage] = await Promise.all([api<Service[]>("/services"), api<Usage>("/usage")]);
  return { services, usage };
}

const num = (n: number) => n.toLocaleString("ko-KR");
const usd = (n: number | null) => (n === null ? "단가 미설정" : `$${n.toFixed(n < 1 ? 4 : 2)}`);

export default function Dashboard() {
  const { services, usage } = useLoaderData<typeof clientLoader>();
  const user = useRouteLoaderData("routes/app-layout") as User;

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-white">안녕하세요, {user.name}님</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{user.email}</p>
      </div>

      <BriefingCard />

      <section>
        <SectionTitle>내 AI 사용량</SectionTitle>
        <div className="grid gap-4 sm:grid-cols-2">
          <Stat label="오늘 사용한 토큰" value={num(usage.me.tokens_today)} hint="자정(한국 시간)에 초기화돼요" />
          <Stat label="이번 달 누적 요청" value={`${num(usage.me.requests_month)}회`} hint="자비스에게 보낸 채팅 횟수" />
        </div>
      </section>

      {usage.admin && <AdminPanel admin={usage.admin} />}

      <section>
        <SectionTitle>연결된 서비스</SectionTitle>

        {services.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-700 dark:bg-slate-900">
            <p className="font-medium text-slate-700 dark:text-slate-200">아직 등록된 서비스가 없어요</p>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
              세컨드 브레인, 일정/예약, 할 일 트래커가 추가되면 여기에 표시됩니다.
            </p>
          </div>
        ) : (
          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {services.map((s) => (
              <li
                key={s.id}
                className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"
              >
                <h3 className="font-semibold text-slate-900 dark:text-white">{s.name}</h3>
                {s.description && (
                  <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{s.description}</p>
                )}
                <p className="mt-3 text-xs text-slate-400">도구 {s.tools.length}개</p>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

function SectionTitle({ children }: { children: React.ReactNode }) {
  return <h2 className="mb-3 text-sm font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{children}</h2>;
}

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
      <p className="text-xs text-slate-500 dark:text-slate-400">{label}</p>
      <p className="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">{value}</p>
      {hint && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
    </div>
  );
}

function AdminPanel({ admin }: { admin: NonNullable<Usage["admin"]> }) {
  const { budget } = admin;
  const percent = budget.used_percent;
  const barTone = percent === null ? "bg-slate-300" : percent >= 100 ? "bg-red-500" : percent >= 80 ? "bg-amber-500" : "bg-indigo-500";

  return (
    <section>
      <SectionTitle>관리자 · 전체 사용자 합계</SectionTitle>

      <div className="grid gap-4 sm:grid-cols-3">
        <Stat label="오늘 사용한 토큰" value={num(admin.tokens_today)} hint="모든 사용자 합계" />
        <Stat label="이번 달 누적 요청" value={`${num(admin.requests_month)}회`} hint="모든 사용자 합계" />
        <Stat
          label="이번 달 예상 API 비용"
          value={usd(admin.estimated_cost_usd)}
          hint="유료 단가 기준 예상치예요. 무료 티어에서는 실제 청구가 없어요."
        />
      </div>

      {admin.unpriced_models.length > 0 && (
        <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">
          단가가 없는 모델이 있어 비용에 일부만 반영됐어요: {admin.unpriced_models.join(", ")}. 서버 설정(
          <code>GEMINI_PRICE_INPUT_PER_M</code>, <code>GEMINI_PRICE_OUTPUT_PER_M</code>)에 단가를 입력하면 계산돼요.
        </p>
      )}

      {admin.llm && (
        <div className="mt-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <div className="flex items-baseline justify-between">
            <p className="text-xs text-slate-500 dark:text-slate-400">AI 연결 상태 (위에서부터 순서대로 시도)</p>
            <p className={`text-xs font-medium ${admin.llm.available ? "text-green-600 dark:text-green-400" : "text-amber-600 dark:text-amber-400"}`}>
              {admin.llm.available ? "사용 가능" : `전부 한도 소진${admin.llm.retry_after ? ` · ${humanizeSeconds(admin.llm.retry_after)} 뒤 복구` : ""}`}
            </p>
          </div>
          <ul className="mt-3 space-y-1.5">
            {admin.llm.models.map((m) => (
              <li key={m.id} className="flex items-center justify-between text-sm">
                <span className="font-mono text-xs text-slate-700 dark:text-slate-200">{m.id}</span>
                <span
                  className={`rounded-full px-2 py-0.5 text-xs ${
                    m.blocked_for > 0
                      ? "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                      : "bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300"
                  }`}
                >
                  {m.blocked_for > 0 ? `한도 소진 · ${humanizeSeconds(m.blocked_for)} 뒤` : "정상"}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="mt-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-baseline justify-between">
          <p className="text-xs text-slate-500 dark:text-slate-400">월간 예산 한도</p>
          <p className="text-sm font-semibold text-slate-900 dark:text-white">
            {budget.monthly_usd === null ? "한도 없음" : `$${budget.monthly_usd}`}
          </p>
        </div>
        {budget.monthly_usd !== null && (
          <>
            <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
              <div className={`h-full ${barTone}`} style={{ width: `${Math.min(100, percent ?? 0)}%` }} />
            </div>
            <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
              {percent === null ? "단가가 없어 사용률을 계산할 수 없어요." : `${percent}% 사용`}
              {" · "}
              {budget.enforced
                ? "한도에 도달하면 AI 호출을 멈추고 키워드 검색으로 대체해요."
                : "단가가 입력되지 않아 한도가 적용되지 않고 있어요."}
            </p>
          </>
        )}
      </div>

      <div className="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <table className="w-full text-left text-sm">
          <caption className="px-5 pt-4 pb-2 text-left text-xs text-slate-500 dark:text-slate-400">모델별 사용량 (이번 달)</caption>
          <thead className="text-xs text-slate-500 dark:text-slate-400">
            <tr>
              <th className="px-5 py-2 font-medium">모델</th>
              <th className="px-3 py-2 text-right font-medium">요청</th>
              <th className="px-3 py-2 text-right font-medium">입력 토큰</th>
              <th className="px-3 py-2 text-right font-medium">출력 토큰</th>
              <th className="px-3 py-2 text-right font-medium">합계</th>
              <th className="px-5 py-2 text-right font-medium">예상 비용</th>
            </tr>
          </thead>
          <tbody className="text-slate-700 dark:text-slate-200">
            {admin.by_model.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-5 py-6 text-center text-slate-400">
                  이번 달 사용 기록이 없어요.
                </td>
              </tr>
            ) : (
              admin.by_model.map((m) => (
                <tr key={m.model} className="border-t border-slate-100 dark:border-slate-800">
                  <td className="px-5 py-2.5 font-mono text-xs">{m.model}</td>
                  <td className="px-3 py-2.5 text-right">{num(m.requests)}</td>
                  <td className="px-3 py-2.5 text-right">{num(m.input_tokens)}</td>
                  <td className="px-3 py-2.5 text-right">{num(m.output_tokens)}</td>
                  <td className="px-3 py-2.5 text-right font-medium">{num(m.total_tokens)}</td>
                  <td className="px-5 py-2.5 text-right">{m.total_tokens === 0 ? "-" : usd(m.estimated_cost_usd)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
}

const time = (iso: string) => new Date(iso).toLocaleTimeString("ko-KR", { hour: "2-digit", minute: "2-digit", hour12: false });
const dayTime = (iso: string) =>
  new Date(iso).toLocaleString("ko-KR", { month: "numeric", day: "numeric", weekday: "short", hour: "2-digit", minute: "2-digit", hour12: false });

/**
 * 오늘의 브리핑. 할 일, 습관, 예약, 가계부를 한 번에 모아 보여 준다.
 * 서버가 각 서비스의 도구를 호출해서 합치므로 AI를 쓰지 않고(무료 한도를 쓰지 않음), 한 서비스가 실패해도 나머지는 나온다.
 * 화면이 먼저 뜨도록 대시보드 로딩과 분리해서 따로 가져온다.
 */
function BriefingCard() {
  const [period, setPeriod] = useState<"today" | "week">("today");
  const [state, setState] = useState<{ data?: Briefing; error?: string }>({});

  useEffect(() => {
    let cancelled = false;
    setState({});
    api<Briefing>(`/briefing?period=${period}`)
      .then((data) => !cancelled && setState({ data }))
      .catch((e) => !cancelled && setState({ error: e instanceof ApiError ? e.message : "브리핑을 불러오지 못했어요." }));
    return () => {
      cancelled = true;
    };
  }, [period]);

  const b = state.data;

  return (
    <section>
      <div className="mb-3 flex items-center justify-between">
        <SectionTitle>{period === "today" ? "오늘의 브리핑" : "이번 주 브리핑"}</SectionTitle>
        <div className="flex gap-1">
          {(["today", "week"] as const).map((p) => (
            <button
              key={p}
              type="button"
              onClick={() => setPeriod(p)}
              className={`rounded-full px-3 py-1 text-xs transition ${
                period === p
                  ? "bg-indigo-600 text-white"
                  : "bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
              }`}
            >
              {p === "today" ? "오늘" : "이번 주"}
            </button>
          ))}
        </div>
      </div>

      {state.error ? (
        <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{state.error}</p>
      ) : !b ? (
        <div className="grid gap-4 sm:grid-cols-2" aria-busy="true">
          {[0, 1, 2, 3].map((i) => (
            <div key={i} className="h-28 animate-pulse rounded-2xl bg-slate-100 dark:bg-slate-800" />
          ))}
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2">
          <BriefingBox title="할 일" to="/tasks" section={b.todos}>
            {(t) => (
              <>
                {t.overdue.map((x) => (
                  <Row key={x.id} tone="text-red-600 dark:text-red-400">
                    <span className="truncate">{x.title}</span>
                    <span className="shrink-0 text-xs">마감 지남 {x.due_date?.slice(5).replace("-", "/")}</span>
                  </Row>
                ))}
                {t.due.map((x) => (
                  <Row key={x.id}>
                    <span className="truncate">{x.title}</span>
                    {x.due_date && <span className="shrink-0 text-xs text-slate-400">{x.due_date.slice(5).replace("-", "/")}</span>}
                  </Row>
                ))}
                {t.overdue_count + t.due_count === 0 && <Empty>{period === "today" ? "오늘 마감인 할 일이 없어요." : "열려 있는 할 일이 없어요."}</Empty>}
                {t.completed_this_week !== null && <Note>이번 주에 {t.completed_this_week}개를 끝냈어요</Note>}
              </>
            )}
          </BriefingBox>

          <BriefingBox title="습관" to="/tasks" section={b.habits}>
            {(h) =>
              h.total === 0 ? (
                <Empty>등록된 습관이 없어요.</Empty>
              ) : (
                <>
                  <Note>
                    오늘 {h.checked_today}/{h.total} 완료 · 이번 주 {h.week_checks}/{h.week_possible}
                  </Note>
                  {h.unchecked.map((x) => (
                    <Row key={x.id}>
                      <span className="truncate">{x.name}</span>
                      <span className="shrink-0 text-xs text-slate-400">{x.streak > 0 ? `${x.streak}일 연속 중` : "아직 안 했어요"}</span>
                    </Row>
                  ))}
                  {h.unchecked.length === 0 && <Empty>오늘 습관을 모두 했어요.</Empty>}
                  {h.best_streak && <Note>최장 연속: {h.best_streak.name} {h.best_streak.streak}일</Note>}
                </>
              )
            }
          </BriefingBox>

          <BriefingBox title="예약" to="/schedule" section={b.reservations}>
            {(r) =>
              r.count === 0 ? (
                <Empty>{period === "today" ? "오늘 예약이 없어요." : "7일 안에 예약이 없어요."}</Empty>
              ) : (
                r.items.map((x) => (
                  <Row key={x.id}>
                    <span className="truncate">
                      {x.room}
                      {x.title && <span className="ml-1 text-slate-400">{x.title}</span>}
                    </span>
                    <span className="shrink-0 text-xs text-slate-500">
                      {period === "today" ? time(x.starts_at) : dayTime(x.starts_at)}
                    </span>
                  </Row>
                ))
              )
            }
          </BriefingBox>

          <BriefingBox title="가계부" to="/ledger" section={b.ledger}>
            {(l) => (
              <>
                <Row>
                  <span>이번 달 지출</span>
                  <b>{l.expense.toLocaleString("ko-KR")}원</b>
                </Row>
                <Row>
                  <span>수입</span>
                  <span>{l.income.toLocaleString("ko-KR")}원</span>
                </Row>
                <Row>
                  <span>잔액</span>
                  <span className={l.balance < 0 ? "text-red-600 dark:text-red-400" : ""}>{l.balance.toLocaleString("ko-KR")}원</span>
                </Row>
                {l.top_expense && <Note>가장 큰 지출: {l.top_expense.category} ({l.top_expense.percent}%)</Note>}
              </>
            )}
          </BriefingBox>
        </div>
      )}
    </section>
  );
}

/** 섹션 하나. 서비스가 꺼져 있으면(null) 카드를 숨기고, 실패하면 그 카드에만 오류를 보여 준다. */
function BriefingBox<T>({
  title,
  to,
  section,
  children,
}: {
  title: string;
  to: string;
  section: T | { error: string } | null;
  children: (data: T) => React.ReactNode;
}) {
  if (section === null) return null;

  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
      <div className="mb-2 flex items-center justify-between">
        <h3 className="text-sm font-semibold text-slate-900 dark:text-white">{title}</h3>
        <Link to={to} className="text-xs text-indigo-600 hover:underline dark:text-indigo-400">
          열기
        </Link>
      </div>
      <div className="space-y-1.5 text-sm text-slate-700 dark:text-slate-200">
        {sectionError(section) ? <p className="text-xs text-red-600 dark:text-red-400">{section.error}</p> : children(section as T)}
      </div>
    </div>
  );
}

const Row = ({ children, tone = "" }: { children: React.ReactNode; tone?: string }) => (
  <div className={`flex items-center justify-between gap-3 ${tone}`}>{children}</div>
);
const Empty = ({ children }: { children: React.ReactNode }) => <p className="text-xs text-slate-400">{children}</p>;
const Note = ({ children }: { children: React.ReactNode }) => <p className="pt-1 text-xs text-slate-500 dark:text-slate-400">{children}</p>;
