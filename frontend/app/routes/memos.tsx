import { useEffect, useRef } from "react";
import { Form, Link, useActionData, useLoaderData, useNavigation } from "react-router";

import { api, ApiError, type Memo, type Paginated, type Tag } from "~/lib/api";
import type { Route } from "./+types/memos";

export const meta: Route.MetaFunction = () => [{ title: "세컨드 브레인 | Jarvis" }];

export async function clientLoader({ request }: Route.ClientLoaderArgs) {
  const url = new URL(request.url);
  const q = url.searchParams.get("q") ?? "";
  const tag = url.searchParams.get("tag") ?? "";

  const query = new URLSearchParams();
  if (q) query.set("q", q);
  if (tag) query.set("tag", tag);

  const [memos, tags] = await Promise.all([
    api<Paginated<Memo>>(`/memos?${query}`),
    api<Tag[]>("/tags"),
  ]);
  return { memos, tags, q, tag };
}

export async function clientAction({ request }: Route.ClientActionArgs) {
  const form = await request.formData();
  const intent = form.get("intent");

  try {
    if (intent === "delete") {
      await api(`/memos/${form.get("id")}`, { method: "DELETE" });
      return { deleted: true };
    }

    const tags = String(form.get("tags") ?? "")
      .split(",")
      .map((t) => t.trim())
      .filter(Boolean);
    await api("/memos", {
      method: "POST",
      body: { title: form.get("title") || null, body: form.get("body"), tags },
    });
    return { created: true };
  } catch (e) {
    if (e instanceof ApiError) return { error: e.message, errors: e.errors };
    throw e;
  }
}

const inputClass =
  "w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-white";

export default function Memos() {
  const { memos, tags, q, tag } = useLoaderData<typeof clientLoader>();
  const result = useActionData<typeof clientAction>();
  const navigation = useNavigation();
  const creating = navigation.state === "submitting" && navigation.formData?.get("intent") === "create";
  const formRef = useRef<HTMLFormElement>(null);

  // 저장에 성공했을 때만 작성 폼을 비운다. 실패하면 쓰던 내용을 그대로 둔다.
  useEffect(() => {
    if (result && "created" in result) formRef.current?.reset();
  }, [result]);

  const error = result && "error" in result ? result : null;

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-white">세컨드 브레인</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">메모를 남기고, 태그와 검색으로 다시 찾으세요.</p>
      </div>

      <Form
        ref={formRef}
        method="post"
        className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"
      >
        <input type="hidden" name="intent" value="create" />
        {error && (
          <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
            {error.errors?.body?.[0] ?? error.errors?.tags?.[0] ?? error.error}
          </p>
        )}
        <input name="title" placeholder="제목 (선택)" maxLength={200} className={inputClass} />
        <textarea name="body" placeholder="무엇을 기억해 둘까요?" required rows={4} maxLength={20000} className={inputClass} />
        <div className="flex flex-col gap-3 sm:flex-row">
          <input name="tags" placeholder="태그 (쉼표로 구분, 최대 10개)" className={inputClass} />
          <button
            type="submit"
            disabled={creating}
            className="shrink-0 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {creating ? "저장 중..." : "저장"}
          </button>
        </div>
      </Form>

      <section className="space-y-4">
        <Form method="get" className="flex gap-2">
          <input type="search" name="q" defaultValue={q} placeholder="메모 검색" className={inputClass} />
          {tag && <input type="hidden" name="tag" value={tag} />}
          <button
            type="submit"
            className="shrink-0 rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
          >
            검색
          </button>
        </Form>

        {tags.length > 0 && (
          <div className="flex flex-wrap gap-2">
            <TagLink to="/memos" active={!tag} label="전체" />
            {tags.map((t) => (
              <TagLink key={t.id} to={`/memos?tag=${encodeURIComponent(t.name)}`} active={tag === t.name} label={`#${t.name}`} />
            ))}
          </div>
        )}

        {memos.data.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
            {q || tag ? "조건에 맞는 메모가 없어요." : "아직 메모가 없어요. 위에서 첫 메모를 남겨 보세요."}
          </div>
        ) : (
          <ul className="space-y-3">
            {memos.data.map((m) => (
              <li key={m.id} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div className="flex items-start justify-between gap-4">
                  <div className="min-w-0">
                    {m.title && <h3 className="font-semibold text-slate-900 dark:text-white">{m.title}</h3>}
                    <p className="whitespace-pre-wrap break-words text-sm text-slate-700 dark:text-slate-300">{m.body}</p>
                  </div>
                  <Form
                    method="post"
                    onSubmit={(e) => {
                      if (!confirm("이 메모를 삭제할까요?")) e.preventDefault();
                    }}
                  >
                    <input type="hidden" name="intent" value="delete" />
                    <input type="hidden" name="id" value={m.id} />
                    <button type="submit" className="text-xs text-slate-400 transition hover:text-red-600">
                      삭제
                    </button>
                  </Form>
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                  {m.tags.map((t) => (
                    <Link key={t.id} to={`/memos?tag=${encodeURIComponent(t.name)}`} className="text-indigo-600 hover:underline dark:text-indigo-400">
                      #{t.name}
                    </Link>
                  ))}
                  <span>{new Date(m.created_at).toLocaleString("ko-KR")}</span>
                </div>
              </li>
            ))}
          </ul>
        )}
        <p className="text-xs text-slate-400">총 {memos.total}개</p>
      </section>
    </div>
  );
}

function TagLink({ to, active, label }: { to: string; active: boolean; label: string }) {
  return (
    <Link
      to={to}
      className={`rounded-full px-3 py-1 text-xs transition ${
        active
          ? "bg-indigo-600 text-white"
          : "bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
      }`}
    >
      {label}
    </Link>
  );
}
