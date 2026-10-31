import { useEffect, useRef, useState } from "react";
import { Form, Link, useActionData, useLoaderData, useNavigation } from "react-router";

import { api, ApiError, type LedgerEntry, type LedgerRule, type LedgerSummary, type Paginated } from "~/lib/api";
import type { Route } from "./+types/ledger";

export const meta: Route.MetaFunction = () => [{ title: "가계부 | Jarvis" }];

const thisMonth = () => new Date().toLocaleDateString("sv-SE").slice(0, 7); // YYYY-MM (브라우저 시간대)
const today = () => new Date().toLocaleDateString("sv-SE");

/** YYYY-MM에 n개월을 더한다. 날짜 객체를 거치지 않으므로 31일 같은 "일" 때문에 달이 밀리지 않는다. */
function shiftMonth(month: string, delta: number) {
  const [y, m] = month.split("-").map(Number);
  const index = y * 12 + (m - 1) + delta;
  return `${Math.floor(index / 12)}-${String((index % 12) + 1).padStart(2, "0")}`;
}

export async function clientLoader({ request }: Route.ClientLoaderArgs) {
  const url = new URL(request.url);
  const raw = url.searchParams.get("month");
  const month = raw && /^\d{4}-(0[1-9]|1[0-2])$/.test(raw) ? raw : thisMonth();
  const category = url.searchParams.get("category") ?? "";

  const query = new URLSearchParams({ month });
  if (category) query.set("category", category);

  // 요약과 목록이 먼저 도래한 고정 거래를 반영하므로, 규칙 목록은 그 뒤에 가져와야 "다음 날짜"가 정확하다.
  const [summary, entries, categories] = await Promise.all([
    api<LedgerSummary>(`/ledger/summary?month=${month}`),
    api<Paginated<LedgerEntry>>(`/ledger/entries?${query}`),
    api<string[]>("/ledger/categories"),
  ]);
  const rules = await api<LedgerRule[]>("/ledger/rules");
  return { month, category, summary, entries, categories, rules };
}

export async function clientAction({ request }: Route.ClientActionArgs) {
  const form = await request.formData();
  const intent = String(form.get("intent"));

  try {
    if (intent === "delete") {
      await api(`/ledger/entries/${form.get("id")}`, { method: "DELETE" });
      return { ok: true, intent };
    }

    if (intent === "rule-delete") {
      await api(`/ledger/rules/${form.get("id")}`, { method: "DELETE" });
      return { ok: true, intent };
    }
    if (intent === "rule-toggle") {
      await api(`/ledger/rules/${form.get("id")}`, { method: "PUT", body: { active: form.get("active") === "true" } });
      return { ok: true, intent };
    }
    if (intent === "rule-create" || intent === "rule-update") {
      const num = (k: string) => (form.get(k) ? Number(form.get(k)) : null);
      const rule = {
        type: form.get("type"),
        amount: Number(String(form.get("amount")).replace(/,/g, "")),
        category: form.get("category"),
        memo: form.get("memo") || null,
        frequency: form.get("frequency"),
        // 주기와 맞지 않는 값은 서버가 버리지만, 처음부터 필요한 것만 보낸다.
        day_of_month: num("day_of_month"),
        weekday: num("weekday"),
        month_of_year: num("month_of_year"),
        end_on: form.get("end_on") || null,
        ...(form.get("start_on") ? { start_on: form.get("start_on") } : {}),
      };
      if (intent === "rule-update") {
        await api(`/ledger/rules/${form.get("id")}`, { method: "PUT", body: rule });
      } else {
        await api("/ledger/rules", { method: "POST", body: rule });
      }
      return { ok: true, intent };
    }

    const body = {
      type: form.get("type"),
      amount: Number(String(form.get("amount")).replace(/,/g, "")),
      category: form.get("category"),
      memo: form.get("memo") || null,
      occurred_on: form.get("occurred_on") || null,
    };
    if (intent === "update") {
      await api(`/ledger/entries/${form.get("id")}`, { method: "PUT", body });
    } else {
      await api("/ledger/entries", { method: "POST", body });
    }
    return { ok: true, intent };
  } catch (e) {
    if (e instanceof ApiError) {
      const first = Object.values(e.errors ?? {})[0]?.[0];
      return { error: first ?? e.message, intent };
    }
    throw e;
  }
}

const won = (n: number) => `${n.toLocaleString("ko-KR")}원`;
const card = "rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900";
const inputClass =
  "w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-white";

export default function Ledger() {
  const { month, category, summary, entries, categories, rules } = useLoaderData<typeof clientLoader>();
  const result = useActionData<typeof clientAction>();
  const navigation = useNavigation();
  const adding = navigation.state === "submitting" && navigation.formData?.get("intent") === "create";
  const saving = navigation.state === "submitting" && navigation.formData?.get("intent") === "update";
  const formRef = useRef<HTMLFormElement>(null);
  const [editingId, setEditingId] = useState<number | null>(null);
  // 고정 거래 폼: 새로 만들기(0) 또는 수정 중인 규칙 id. null이면 닫힘.
  const [ruleForm, setRuleForm] = useState<number | 0 | null>(null);

  useEffect(() => {
    if (result && "ok" in result) {
      if (result.intent === "create") formRef.current?.reset();
      if (result.intent === "update") setEditingId(null);
      if (result.intent === "rule-create" || result.intent === "rule-update") setRuleForm(null);
    }
  }, [result]);

  const error = result && "error" in result ? result.error : null;
  const ruleError = error && result && "intent" in result && String(result.intent).startsWith("rule-") ? error : null;
  const [y, m] = month.split("-");

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-white">가계부</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          자비스에게는 월별 합계만 전달돼요. 개별 거래 내역과 메모는 이 화면에서만 볼 수 있어요.
        </p>
      </div>

      <div className="flex items-center justify-center gap-4">
        <Link to={`/ledger?month=${shiftMonth(month, -1)}`} className="rounded-lg px-3 py-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="이전 달">
          ←
        </Link>
        <span className="text-lg font-semibold text-slate-900 dark:text-white">
          {y}년 {Number(m)}월
        </span>
        <Link to={`/ledger?month=${shiftMonth(month, 1)}`} className="rounded-lg px-3 py-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="다음 달">
          →
        </Link>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <Stat label="수입" value={won(summary.income)} tone="text-blue-600 dark:text-blue-400" />
        <Stat label="지출" value={won(summary.expense)} tone="text-red-600 dark:text-red-400" />
        <Stat label="잔액" value={`${summary.balance < 0 ? "-" : ""}${won(Math.abs(summary.balance))}`} tone={summary.balance < 0 ? "text-red-600 dark:text-red-400" : "text-slate-900 dark:text-white"} />
      </div>

      {(summary.fixed.expense > 0 || summary.fixed.upcoming_expense > 0 || summary.fixed.income > 0 || summary.fixed.upcoming_income > 0) && (
        <p className="rounded-xl bg-slate-100 px-4 py-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">
          고정 거래: 지출 <b>{won(summary.fixed.expense)}</b> 반영
          {summary.fixed.upcoming_expense > 0 && <> · 예정 <b>{won(summary.fixed.upcoming_expense)}</b></>}
          {(summary.fixed.income > 0 || summary.fixed.upcoming_income > 0) && (
            <> · 수입 <b>{won(summary.fixed.income)}</b> 반영{summary.fixed.upcoming_income > 0 && <> · 예정 <b>{won(summary.fixed.upcoming_income)}</b></>}</>
          )}
        </p>
      )}

      {summary.expense_by_category.length > 0 && (
        <section className={`${card} p-5`}>
          <h2 className="mb-3 text-sm font-medium text-slate-700 dark:text-slate-200">카테고리별 지출</h2>
          <ul className="space-y-3">
            {summary.expense_by_category.map((c) => (
              <li key={c.category}>
                <div className="flex items-baseline justify-between text-sm">
                  <Link to={`/ledger?month=${month}&category=${encodeURIComponent(c.category)}`} className="text-slate-700 hover:underline dark:text-slate-200">
                    {c.category} <span className="text-xs text-slate-400">{c.count}건</span>
                  </Link>
                  <span className="font-medium text-slate-900 dark:text-white">
                    {won(c.total)} <span className="text-xs font-normal text-slate-400">{c.percent}%</span>
                  </span>
                </div>
                <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                  <div className="h-full rounded-full bg-indigo-500" style={{ width: `${c.percent}%` }} />
                </div>
              </li>
            ))}
          </ul>
        </section>
      )}

      <Form ref={formRef} method="post" className={`${card} space-y-3 p-5`}>
        <input type="hidden" name="intent" value="create" />
        <h2 className="text-sm font-medium text-slate-700 dark:text-slate-200">거래 기록</h2>
        {error && !ruleError && !editingId && (
          <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
            {error}
          </p>
        )}
        <div className="grid gap-3 sm:grid-cols-4">
          <select name="type" defaultValue="expense" className={inputClass} aria-label="구분">
            <option value="expense">지출</option>
            <option value="income">수입</option>
          </select>
          <input name="amount" inputMode="numeric" placeholder="금액(원)" required className={inputClass} />
          <input name="category" list="ledger-categories" placeholder="카테고리" required maxLength={50} className={inputClass} />
          <input type="date" name="occurred_on" defaultValue={today()} max={today()} className={inputClass} aria-label="날짜" />
        </div>
        <datalist id="ledger-categories">
          {categories.map((c) => (
            <option key={c} value={c} />
          ))}
        </datalist>
        <div className="flex gap-3">
          <input name="memo" placeholder="메모 (선택)" maxLength={200} className={inputClass} />
          <button
            type="submit"
            disabled={adding}
            className="shrink-0 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:opacity-60"
          >
            {adding ? "저장 중..." : "저장"}
          </button>
        </div>
      </Form>

      <section className="space-y-3">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-sm font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">고정 거래</h2>
            <p className="mt-0.5 text-xs text-slate-400">월세, 구독료, 월급처럼 반복되는 거래예요. 날짜가 되면 자동으로 기록돼요.</p>
          </div>
          {ruleForm === null && (
            <button
              type="button"
              onClick={() => setRuleForm(0)}
              className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-600 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
            >
              + 추가
            </button>
          )}
        </div>

        {ruleForm === 0 && <RuleForm categories={categories} error={ruleError} onCancel={() => setRuleForm(null)} />}

        {rules.length === 0 && ruleForm !== 0 ? (
          <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
            등록된 고정 거래가 없어요.
          </div>
        ) : (
          <ul className="space-y-2">
            {rules.map((r) =>
              ruleForm === r.id ? (
                <li key={r.id}>
                  <RuleForm rule={r} categories={categories} error={ruleError} onCancel={() => setRuleForm(null)} />
                </li>
              ) : (
                <li key={r.id} className={`${card} flex items-center justify-between gap-4 p-4 ${r.active ? "" : "opacity-60"}`}>
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-slate-900 dark:text-white">
                      {r.category}
                      {r.memo && <span className="ml-2 font-normal text-slate-500 dark:text-slate-400">{r.memo}</span>}
                      {!r.active && <span className="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-slate-800">일시정지</span>}
                    </p>
                    <p className="text-xs text-slate-400">
                      {scheduleText(r)}
                      {r.next_on && r.active && ` · 다음 ${r.next_on}`}
                      {r.end_on && ` · ${r.end_on}까지`}
                    </p>
                  </div>
                  <div className="flex shrink-0 items-center gap-3">
                    <span className={`text-sm font-semibold ${r.type === "income" ? "text-blue-600 dark:text-blue-400" : "text-slate-900 dark:text-white"}`}>
                      {r.type === "income" ? "+" : "-"}
                      {won(r.amount)}
                    </span>
                    <button type="button" onClick={() => setRuleForm(r.id)} className="text-xs text-slate-400 transition hover:text-indigo-600">
                      수정
                    </button>
                    <Form method="post">
                      <input type="hidden" name="intent" value="rule-toggle" />
                      <input type="hidden" name="id" value={r.id} />
                      <input type="hidden" name="active" value={String(!r.active)} />
                      <button type="submit" className="text-xs text-slate-400 transition hover:text-indigo-600">
                        {r.active ? "일시정지" : "다시 시작"}
                      </button>
                    </Form>
                    <Form
                      method="post"
                      onSubmit={(ev) => {
                        if (!confirm("이 고정 거래를 삭제할까요? 이미 기록된 거래는 그대로 남아요.")) ev.preventDefault();
                      }}
                    >
                      <input type="hidden" name="intent" value="rule-delete" />
                      <input type="hidden" name="id" value={r.id} />
                      <button type="submit" className="text-xs text-slate-400 transition hover:text-red-600">
                        삭제
                      </button>
                    </Form>
                  </div>
                </li>
              ),
            )}
          </ul>
        )}
      </section>

      <section className="space-y-3">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
            거래 내역 {category && <span className="normal-case">· {category}</span>}
          </h2>
          {category && (
            <Link to={`/ledger?month=${month}`} className="text-xs text-indigo-600 hover:underline dark:text-indigo-400">
              필터 해제
            </Link>
          )}
        </div>

        {entries.data.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
            이 달에는 기록이 없어요.
          </div>
        ) : (
          <ul className="space-y-2">
            {entries.data.map((e) =>
              editingId === e.id ? (
                <li key={e.id} className={`${card} p-4`}>
                  <Form method="post" className="space-y-3">
                    <input type="hidden" name="intent" value="update" />
                    <input type="hidden" name="id" value={e.id} />
                    {error && !ruleError && (
                      <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
                        {error}
                      </p>
                    )}
                    <div className="grid gap-3 sm:grid-cols-4">
                      <select name="type" defaultValue={e.type} className={inputClass} aria-label="구분">
                        <option value="expense">지출</option>
                        <option value="income">수입</option>
                      </select>
                      <input name="amount" inputMode="numeric" defaultValue={e.amount} required className={inputClass} />
                      <input name="category" list="ledger-categories" defaultValue={e.category} required maxLength={50} className={inputClass} />
                      <input type="date" name="occurred_on" defaultValue={e.occurred_on} max={today()} className={inputClass} aria-label="날짜" />
                    </div>
                    <input name="memo" defaultValue={e.memo ?? ""} placeholder="메모 (선택)" maxLength={200} className={inputClass} />
                    <div className="flex gap-2">
                      <button type="submit" disabled={saving} className="rounded-lg bg-indigo-600 px-4 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-60">
                        {saving ? "저장 중..." : "저장"}
                      </button>
                      <button type="button" onClick={() => setEditingId(null)} className="rounded-lg border border-slate-300 px-4 py-1.5 text-xs text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                        취소
                      </button>
                    </div>
                  </Form>
                </li>
              ) : (
                <li key={e.id} className={`${card} flex items-center justify-between gap-4 p-4`}>
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-slate-900 dark:text-white">
                      {e.category}
                      {e.memo && <span className="ml-2 font-normal text-slate-500 dark:text-slate-400">{e.memo}</span>}
                      {e.recurring && <span className="ml-2 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-normal text-indigo-600 dark:bg-indigo-950 dark:text-indigo-300">고정</span>}
                    </p>
                    <p className="text-xs text-slate-400">{e.occurred_on}</p>
                  </div>
                  <div className="flex shrink-0 items-center gap-3">
                    <span className={`text-sm font-semibold ${e.type === "income" ? "text-blue-600 dark:text-blue-400" : "text-slate-900 dark:text-white"}`}>
                      {e.type === "income" ? "+" : "-"}
                      {won(e.amount)}
                    </span>
                    <button type="button" onClick={() => setEditingId(e.id)} className="text-xs text-slate-400 transition hover:text-indigo-600">
                      수정
                    </button>
                    <Form
                      method="post"
                      onSubmit={(ev) => {
                        if (!confirm("이 거래를 삭제할까요?")) ev.preventDefault();
                      }}
                    >
                      <input type="hidden" name="intent" value="delete" />
                      <input type="hidden" name="id" value={e.id} />
                      <button type="submit" className="text-xs text-slate-400 transition hover:text-red-600">
                        삭제
                      </button>
                    </Form>
                  </div>
                </li>
              ),
            )}
          </ul>
        )}
        {entries.last_page > 1 && (
          <p className="text-center text-xs text-slate-400">
            최근 {entries.data.length}건만 표시돼요 (전체 {entries.total}건). 카테고리로 좁혀 보세요.
          </p>
        )}
      </section>
    </div>
  );
}

function Stat({ label, value, tone }: { label: string; value: string; tone: string }) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
      <p className="text-xs text-slate-500 dark:text-slate-400">{label}</p>
      <p className={`mt-2 text-xl font-semibold ${tone}`}>{value}</p>
    </div>
  );
}

const WEEKDAYS = ["월", "화", "수", "목", "금", "토", "일"]; // ISO: 1=월 ... 7=일

/** "매월 25일", "매주 수요일", "매년 3월 15일". 31일은 짧은 달에 말일로 처리된다. */
function scheduleText(r: LedgerRule) {
  if (r.frequency === "weekly") return `매주 ${WEEKDAYS[(r.weekday ?? 1) - 1]}요일`;
  if (r.frequency === "yearly") return `매년 ${r.month_of_year}월 ${r.day_of_month}일`;
  return `매월 ${r.day_of_month}일${(r.day_of_month ?? 0) > 28 ? " (짧은 달은 말일)" : ""}`;
}

/** 고정 거래 만들기/수정 폼. 주기에 따라 필요한 입력만 보여 준다. */
function RuleForm({ rule, categories, error, onCancel }: { rule?: LedgerRule; categories: string[]; error: string | null; onCancel: () => void }) {
  const [frequency, setFrequency] = useState<LedgerRule["frequency"]>(rule?.frequency ?? "monthly");
  const submitting = useNavigation().state === "submitting";

  return (
    <Form method="post" className={`${card} space-y-3 p-4`}>
      <input type="hidden" name="intent" value={rule ? "rule-update" : "rule-create"} />
      {rule && <input type="hidden" name="id" value={rule.id} />}
      {error && (
        <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
          {error}
        </p>
      )}
      <div className="grid gap-3 sm:grid-cols-4">
        <select name="type" defaultValue={rule?.type ?? "expense"} className={inputClass} aria-label="구분">
          <option value="expense">지출</option>
          <option value="income">수입</option>
        </select>
        <input name="amount" inputMode="numeric" placeholder="금액(원)" defaultValue={rule?.amount} required className={inputClass} />
        <input name="category" list="ledger-categories" placeholder="카테고리 (예: 월세)" defaultValue={rule?.category} required maxLength={50} className={inputClass} />
        <input name="memo" placeholder="메모 (선택)" defaultValue={rule?.memo ?? ""} maxLength={200} className={inputClass} />
      </div>

      <div className="grid gap-3 sm:grid-cols-4">
        <select name="frequency" value={frequency} onChange={(e) => setFrequency(e.target.value as LedgerRule["frequency"])} className={inputClass} aria-label="주기">
          <option value="monthly">매월</option>
          <option value="weekly">매주</option>
          <option value="yearly">매년</option>
        </select>

        {frequency === "yearly" && (
          <select name="month_of_year" defaultValue={rule?.month_of_year ?? new Date().getMonth() + 1} className={inputClass} aria-label="월">
            {Array.from({ length: 12 }, (_, i) => (
              <option key={i + 1} value={i + 1}>
                {i + 1}월
              </option>
            ))}
          </select>
        )}
        {frequency !== "weekly" && (
          <select name="day_of_month" defaultValue={rule?.day_of_month ?? new Date().getDate()} className={inputClass} aria-label="일">
            {Array.from({ length: 31 }, (_, i) => (
              <option key={i + 1} value={i + 1}>
                {i + 1}일
              </option>
            ))}
          </select>
        )}
        {frequency === "weekly" && (
          <select name="weekday" defaultValue={rule?.weekday ?? 1} className={inputClass} aria-label="요일">
            {WEEKDAYS.map((d, i) => (
              <option key={d} value={i + 1}>
                {d}요일
              </option>
            ))}
          </select>
        )}

        {/* 시작일은 만들 때만 정한다. 수정은 "지금부터" 적용되어 과거를 바꾸지 않는다. */}
        {!rule && <input type="date" name="start_on" defaultValue={today()} className={inputClass} aria-label="시작일" title="시작일 (과거면 그날부터 반영해요)" />}
        <input type="date" name="end_on" defaultValue={rule?.end_on ?? ""} className={inputClass} aria-label="종료일 (선택)" title="종료일 (선택)" />
      </div>

      <p className="text-xs text-slate-400">
        {frequency === "monthly" && "31일처럼 없는 날이 있는 달은 말일에 기록돼요. "}
        {rule ? "수정한 내용은 지금부터 적용돼요. 이미 기록된 거래는 바뀌지 않아요." : "시작일이 오늘이거나 과거면 바로 반영돼요."}
      </p>

      <div className="flex gap-2">
        <button type="submit" disabled={submitting} className="rounded-lg bg-indigo-600 px-4 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-60">
          {submitting ? "저장 중..." : "저장"}
        </button>
        <button type="button" onClick={onCancel} className="rounded-lg border border-slate-300 px-4 py-1.5 text-xs text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
          취소
        </button>
      </div>
    </Form>
  );
}
