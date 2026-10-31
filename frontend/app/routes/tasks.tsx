import { useEffect, useRef } from "react";
import { Form, Link, useActionData, useLoaderData, useNavigation } from "react-router";

import { api, ApiError, type Habit, type Todo } from "~/lib/api";
import type { Route } from "./+types/tasks";

export const meta: Route.MetaFunction = () => [{ title: "할 일/습관 | Jarvis" }];

const VIEWS = [
  { value: "open", label: "미완료" },
  { value: "today", label: "오늘까지" },
  { value: "done", label: "완료" },
] as const;

export async function clientLoader({ request }: Route.ClientLoaderArgs) {
  const raw = new URL(request.url).searchParams.get("view");
  const view = VIEWS.some((v) => v.value === raw) ? (raw as string) : "open";

  const [todos, habits] = await Promise.all([api<Todo[]>(`/todos?scope=${view}`), api<Habit[]>("/habits")]);
  return { todos, habits, view };
}

export async function clientAction({ request }: Route.ClientActionArgs) {
  const form = await request.formData();
  const intent = String(form.get("intent"));
  const id = form.get("id");

  try {
    switch (intent) {
      case "todo-add":
        await api("/todos", { method: "POST", body: { title: form.get("title"), due_date: form.get("due_date") || null } });
        break;
      case "todo-complete":
      case "todo-reopen":
        await api(`/todos/${id}/${intent === "todo-complete" ? "complete" : "reopen"}`, { method: "POST" });
        break;
      case "todo-delete":
        await api(`/todos/${id}`, { method: "DELETE" });
        break;
      case "habit-add":
        await api("/habits", { method: "POST", body: { name: form.get("name") } });
        break;
      case "habit-check":
      case "habit-uncheck":
        await api(`/habits/${id}/${intent === "habit-check" ? "check" : "uncheck"}`, { method: "POST" });
        break;
      case "habit-delete":
        await api(`/habits/${id}`, { method: "DELETE" });
        break;
    }
    return { ok: true, intent };
  } catch (e) {
    if (e instanceof ApiError) return { error: e.errors?.title?.[0] ?? e.errors?.name?.[0] ?? e.errors?.due_date?.[0] ?? e.message, intent };
    throw e;
  }
}

const inputClass =
  "w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-white";
const card = "rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900";

const today = () => new Date().toLocaleDateString("sv-SE"); // YYYY-MM-DD (브라우저 시간대)

function dueLabel(todo: Todo) {
  if (!todo.due_date) return null;
  const [, m, d] = todo.due_date.split("-");
  if (todo.due_date === today()) return "오늘";
  return `${Number(m)}/${Number(d)}`;
}

export default function Tasks() {
  const { todos, habits, view } = useLoaderData<typeof clientLoader>();
  const result = useActionData<typeof clientAction>();
  const navigation = useNavigation();
  const submittingIntent = navigation.state === "submitting" ? String(navigation.formData?.get("intent")) : null;
  const todoForm = useRef<HTMLFormElement>(null);
  const habitForm = useRef<HTMLFormElement>(null);

  // 추가에 성공했을 때만 입력칸을 비운다. 실패하면 쓰던 내용을 남긴다.
  useEffect(() => {
    if (result && "ok" in result) {
      if (result.intent === "todo-add") todoForm.current?.reset();
      if (result.intent === "habit-add") habitForm.current?.reset();
    }
  }, [result]);

  const error = result && "error" in result ? result.error : null;

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-white">할 일/습관</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">할 일은 마감일과 함께, 습관은 매일 체크하며 연속 달성일을 쌓아 보세요.</p>
      </div>

      {error && (
        <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
          {error}
        </p>
      )}

      <div className="grid gap-8 lg:grid-cols-2">
        <section className="space-y-4">
          <h2 className="text-sm font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">할 일</h2>

          <Form ref={todoForm} method="post" className={`${card} space-y-3 p-4`}>
            <input type="hidden" name="intent" value="todo-add" />
            <input name="title" placeholder="무엇을 해야 하나요?" required maxLength={200} className={inputClass} />
            <div className="flex gap-2">
              <input type="date" name="due_date" className={inputClass} aria-label="마감일 (선택)" />
              <button
                type="submit"
                disabled={submittingIntent === "todo-add"}
                className="shrink-0 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:opacity-60"
              >
                추가
              </button>
            </div>
          </Form>

          <div className="flex gap-2">
            {VIEWS.map((v) => (
              <Link
                key={v.value}
                to={`/tasks?view=${v.value}`}
                className={`rounded-full px-3 py-1 text-xs transition ${
                  view === v.value
                    ? "bg-indigo-600 text-white"
                    : "bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                }`}
              >
                {v.label}
              </Link>
            ))}
          </div>

          {todos.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
              {view === "done" ? "완료한 할 일이 없어요." : "할 일이 없어요. 위에서 추가해 보세요."}
            </div>
          ) : (
            <ul className="space-y-2">
              {todos.map((t) => (
                <li key={t.id} className={`${card} flex items-center gap-3 p-3`}>
                  <Form method="post">
                    <input type="hidden" name="intent" value={t.completed ? "todo-reopen" : "todo-complete"} />
                    <input type="hidden" name="id" value={t.id} />
                    <button
                      type="submit"
                      aria-label={t.completed ? "완료 취소" : "완료로 표시"}
                      className={`flex h-5 w-5 items-center justify-center rounded-full border text-xs transition ${
                        t.completed
                          ? "border-indigo-600 bg-indigo-600 text-white"
                          : "border-slate-300 text-transparent hover:border-indigo-500 hover:text-indigo-500 dark:border-slate-600"
                      }`}
                    >
                      ✓
                    </button>
                  </Form>
                  <span
                    className={`min-w-0 flex-1 break-words text-sm ${
                      t.completed ? "text-slate-400 line-through" : "text-slate-800 dark:text-slate-100"
                    }`}
                  >
                    {t.title}
                  </span>
                  {dueLabel(t) && (
                    <span
                      className={`shrink-0 rounded-full px-2 py-0.5 text-xs ${
                        t.overdue
                          ? "bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300"
                          : "bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400"
                      }`}
                    >
                      {t.overdue ? `지남 ${dueLabel(t)}` : dueLabel(t)}
                    </span>
                  )}
                  <Form
                    method="post"
                    onSubmit={(e) => {
                      if (!confirm("이 할 일을 삭제할까요?")) e.preventDefault();
                    }}
                  >
                    <input type="hidden" name="intent" value="todo-delete" />
                    <input type="hidden" name="id" value={t.id} />
                    <button type="submit" className="text-xs text-slate-400 transition hover:text-red-600">
                      삭제
                    </button>
                  </Form>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="space-y-4">
          <h2 className="text-sm font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">습관</h2>

          <Form ref={habitForm} method="post" className={`${card} flex gap-2 p-4`}>
            <input type="hidden" name="intent" value="habit-add" />
            <input name="name" placeholder="새 습관 (예: 운동)" required maxLength={100} className={inputClass} />
            <button
              type="submit"
              disabled={submittingIntent === "habit-add"}
              className="shrink-0 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:opacity-60"
            >
              추가
            </button>
          </Form>

          {habits.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
              아직 습관이 없어요. 하나 만들어 보세요.
            </div>
          ) : (
            <ul className="space-y-2">
              {habits.map((h) => (
                <li key={h.id} className={`${card} flex items-center justify-between gap-3 p-4`}>
                  <div className="min-w-0">
                    <p className="truncate font-medium text-slate-900 dark:text-white">{h.name}</p>
                    <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                      {h.streak > 0 ? `${h.streak}일 연속` : "연속 기록 없음"} · 이번 주 {h.week_checks}/{h.week_days}
                    </p>
                  </div>
                  <div className="flex shrink-0 items-center gap-3">
                    <Form method="post">
                      <input type="hidden" name="intent" value={h.checked_today ? "habit-uncheck" : "habit-check"} />
                      <input type="hidden" name="id" value={h.id} />
                      <button
                        type="submit"
                        className={`rounded-lg px-3 py-1.5 text-xs font-medium transition ${
                          h.checked_today
                            ? "bg-green-100 text-green-700 hover:bg-green-200 dark:bg-green-950 dark:text-green-300"
                            : "bg-indigo-600 text-white hover:bg-indigo-500"
                        }`}
                      >
                        {h.checked_today ? "✓ 오늘 완료" : "오늘 체크"}
                      </button>
                    </Form>
                    <Form
                      method="post"
                      onSubmit={(e) => {
                        if (!confirm(`'${h.name}' 습관과 기록을 모두 삭제할까요?`)) e.preventDefault();
                      }}
                    >
                      <input type="hidden" name="intent" value="habit-delete" />
                      <input type="hidden" name="id" value={h.id} />
                      <button type="submit" className="text-xs text-slate-400 transition hover:text-red-600">
                        삭제
                      </button>
                    </Form>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </div>
  );
}
