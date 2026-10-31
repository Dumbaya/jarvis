import { useEffect, useRef, useState } from "react";
import { Form, Link, useActionData, useLoaderData, useNavigation } from "react-router";

import { api, ApiError, type LedgerEntry, type LedgerSummary, type Paginated } from "~/lib/api";
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

  const [summary, entries, categories] = await Promise.all([
    api<LedgerSummary>(`/ledger/summary?month=${month}`),
    api<Paginated<LedgerEntry>>(`/ledger/entries?${query}`),
    api<string[]>("/ledger/categories"),
  ]);
  return { month, category, summary, entries, categories };
}

export async function clientAction({ request }: Route.ClientActionArgs) {
  const form = await request.formData();
  const intent = String(form.get("intent"));

  try {
    if (intent === "delete") {
      await api(`/ledger/entries/${form.get("id")}`, { method: "DELETE" });
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
  const { month, category, summary, entries, categories } = useLoaderData<typeof clientLoader>();
  const result = useActionData<typeof clientAction>();
  const navigation = useNavigation();
  const adding = navigation.state === "submitting" && navigation.formData?.get("intent") === "create";
  const saving = navigation.state === "submitting" && navigation.formData?.get("intent") === "update";
  const formRef = useRef<HTMLFormElement>(null);
  const [editingId, setEditingId] = useState<number | null>(null);

  useEffect(() => {
    if (result && "ok" in result) {
      if (result.intent === "create") formRef.current?.reset();
      if (result.intent === "update") setEditingId(null);
    }
  }, [result]);

  const error = result && "error" in result ? result.error : null;
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
        {error && !editingId && (
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
                    {error && (
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
