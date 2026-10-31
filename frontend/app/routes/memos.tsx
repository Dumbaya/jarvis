import { useEffect, useRef, useState } from "react";
import { Form, Link, useActionData, useLoaderData, useNavigation, useRevalidator } from "react-router";

import { api, ApiError, humanizeSeconds, type Memo, type MemoAiStatus, type Paginated, type SemanticInfo, type Tag } from "~/lib/api";
import type { Route } from "./+types/memos";

export const meta: Route.MetaFunction = () => [{ title: "세컨드 브레인 | Jarvis" }];

export async function clientLoader({ request }: Route.ClientLoaderArgs) {
  const url = new URL(request.url);
  const q = url.searchParams.get("q") ?? "";
  const tag = url.searchParams.get("tag") ?? "";

  const query = new URLSearchParams();
  if (q) query.set("q", q);
  if (tag) query.set("tag", tag);

  // 검색어가 있으면 키워드+의미 하이브리드 검색, 없으면 최신순 전체 목록.
  const list = async (): Promise<{ memos: { data: Memo[]; total: number }; semantic: SemanticInfo | null }> => {
    if (q) {
      const r = await api<{ data: Memo[]; total: number; semantic: SemanticInfo }>(`/memos/search?${query}&limit=10`);
      return { memos: { data: r.data, total: r.total }, semantic: r.semantic };
    }
    const r = await api<Paginated<Memo>>(`/memos?${query}`);
    return { memos: { data: r.data, total: r.total }, semantic: null };
  };

  const [{ memos, semantic }, tags, ai] = await Promise.all([list(), api<Tag[]>("/tags"), api<MemoAiStatus>("/memos/ai")]);
  return { memos, semantic, tags, ai, q, tag };
}

export async function clientAction({ request }: Route.ClientActionArgs) {
  const form = await request.formData();
  const intent = form.get("intent");

  try {
    if (intent === "delete") {
      await api(`/memos/${form.get("id")}`, { method: "DELETE" });
      return { deleted: true };
    }
    if (intent === "ai-toggle") {
      await api("/memos/ai", { method: "PUT", body: { enabled: form.get("enabled") === "true" } });
      return { aiToggled: true };
    }

    const tags = String(form.get("tags") ?? "")
      .split(",")
      .map((t) => t.trim())
      .filter(Boolean);
    const body = { title: form.get("title") || null, body: form.get("body"), tags };

    if (intent === "update") {
      await api(`/memos/${form.get("id")}`, { method: "PUT", body });
      return { updated: true };
    }

    await api("/memos", { method: "POST", body });
    return { created: true };
  } catch (e) {
    if (e instanceof ApiError) return { error: e.message, errors: e.errors };
    throw e;
  }
}

const inputClass =
  "w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-white";

export default function Memos() {
  const { memos, semantic, tags, ai, q, tag } = useLoaderData<typeof clientLoader>();
  const searching = q !== "";
  const result = useActionData<typeof clientAction>();
  const navigation = useNavigation();
  const creating = navigation.state === "submitting" && navigation.formData?.get("intent") === "create";
  const formRef = useRef<HTMLFormElement>(null);
  const [editingId, setEditingId] = useState<number | null>(null);
  const saving = navigation.state === "submitting" && navigation.formData?.get("intent") === "update";

  // 저장에 성공했을 때만 작성 폼을 비운다. 실패하면 쓰던 내용을 그대로 둔다.
  useEffect(() => {
    if (result && "created" in result) formRef.current?.reset();
    // 수정이 성공하면 수정 모드를 닫고, 실패하면 열어 둔 채 오류를 보여 준다.
    if (result && "updated" in result) setEditingId(null);
  }, [result]);

  const error = result && "error" in result ? result : null;

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-white">세컨드 브레인</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">메모를 남기고, 태그와 검색으로 다시 찾으세요.</p>
      </div>

      <AiPanel ai={ai} />

      <Form
        ref={formRef}
        method="post"
        className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"
      >
        <input type="hidden" name="intent" value="create" />
        {error && editingId === null && (
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
          <input
            type="search"
            name="q"
            defaultValue={q}
            placeholder={ai.enabled ? "메모 검색 (질문하듯 물어봐도 돼요)" : "메모 검색"}
            className={inputClass}
          />
          {tag && <input type="hidden" name="tag" value={tag} />}
          <button
            type="submit"
            className="shrink-0 rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
          >
            검색
          </button>
        </Form>

        {searching && semantic && !semantic.used && <SemanticNotice reason={semantic.reason} retryAfter={ai.retry_after} />}

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
                {editingId === m.id && !searching ? (
                  <Form method="post" className="space-y-3">
                    <input type="hidden" name="intent" value="update" />
                    <input type="hidden" name="id" value={m.id} />
                    {error && (
                      <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
                        {error.errors?.body?.[0] ?? error.errors?.tags?.[0] ?? error.error}
                      </p>
                    )}
                    <input name="title" defaultValue={m.title ?? ""} placeholder="제목 (선택)" maxLength={200} className={inputClass} />
                    <textarea name="body" defaultValue={m.body} required rows={4} maxLength={20000} className={inputClass} />
                    <input name="tags" defaultValue={m.tags.map((t) => t.name).join(", ")} placeholder="태그 (쉼표로 구분)" className={inputClass} />
                    <div className="flex gap-2">
                      <button
                        type="submit"
                        disabled={saving}
                        className="rounded-lg bg-indigo-600 px-4 py-1.5 text-xs font-medium text-white transition hover:bg-indigo-500 disabled:opacity-60"
                      >
                        {saving ? "저장 중..." : "저장"}
                      </button>
                      <button
                        type="button"
                        onClick={() => setEditingId(null)}
                        className="rounded-lg border border-slate-300 px-4 py-1.5 text-xs text-slate-600 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                      >
                        취소
                      </button>
                    </div>
                  </Form>
                ) : (
                  <>
                    <div className="flex items-start justify-between gap-4">
                      <div className="min-w-0">
                        {(m.match || m.title) && (
                          <div className="mb-1 flex flex-wrap items-center gap-2">
                            {m.title && <h3 className="font-semibold text-slate-900 dark:text-white">{m.title}</h3>}
                            {m.match && <MatchBadge match={m.match} similarity={m.similarity ?? null} />}
                          </div>
                        )}
                        <p className="whitespace-pre-wrap break-words text-sm text-slate-700 dark:text-slate-300">{m.body}</p>
                        {/* 의미 검색이 근거로 고른 문단. 본문 앞부분과 다를 때만 보여 준다. */}
                        {m.excerpt && !m.body.startsWith(m.excerpt.replace(/…$/, "")) && (
                          <p className="mt-2 border-l-2 border-indigo-300 pl-3 text-xs text-slate-500 dark:border-indigo-700 dark:text-slate-400">
                            <span className="font-medium">근거 문단</span> · {m.excerpt}
                          </p>
                        )}
                      </div>
                      <div className="flex shrink-0 items-center gap-3">
                        {/* 검색 결과의 본문은 앞부분만 와서 그대로 수정하면 뒷부분이 잘려 저장된다. 수정은 전체 목록에서 한다. */}
                        {!searching && (
                          <button
                            type="button"
                            onClick={() => setEditingId(m.id)}
                            className="text-xs text-slate-400 transition hover:text-indigo-600"
                          >
                            수정
                          </button>
                        )}
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
                    </div>
                <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                  {m.tags.map((t) => (
                    <Link key={t.id} to={`/memos?tag=${encodeURIComponent(t.name)}`} className="text-indigo-600 hover:underline dark:text-indigo-400">
                      #{t.name}
                    </Link>
                  ))}
                  <span>{new Date(m.created_at).toLocaleString("ko-KR")}</span>
                </div>
                  </>
                )}
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

/** 검색 결과가 어떤 방식으로 찾아졌는지. 의미 일치는 유사도도 보여 준다. */
function MatchBadge({ match, similarity }: { match: NonNullable<Memo["match"]>; similarity: number | null }) {
  const label = match === "both" ? "단어+의미 일치" : match === "semantic" ? "의미 일치" : "단어 일치";
  const tone =
    match === "keyword"
      ? "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300"
      : "bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300";

  return (
    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${tone}`}>
      {label}
      {similarity !== null && ` ${Math.round(similarity * 100)}%`}
    </span>
  );
}

const NOTICES: Record<NonNullable<SemanticInfo["reason"]>, string> = {
  disabled: "의미 검색이 꺼져 있어서 단어가 일치하는 메모만 찾았어요.",
  indexing: "메모를 색인하는 중이라 지금은 단어가 일치하는 메모만 찾았어요.",
  unavailable: "의미 검색을 지금 쓸 수 없어서 단어가 일치하는 메모만 찾았어요.",
  quota: "임베딩 무료 사용량을 모두 써서 단어가 일치하는 메모만 찾았어요.",
};

function SemanticNotice({ reason, retryAfter }: { reason: SemanticInfo["reason"]; retryAfter: number | null }) {
  if (!reason) return null;

  return (
    <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">
      {NOTICES[reason]}
      {reason === "quota" && retryAfter ? ` (${humanizeSeconds(retryAfter)} 뒤에 다시 쓸 수 있어요)` : ""}
    </p>
  );
}

/**
 * 의미 검색 켜기/끄기. 켜는 순간부터 메모 내용이 외부 임베딩 제공자로 전송되므로 무엇이 나가는지 먼저 알리고 확인을 받는다.
 * 끄면 저장된 벡터가 모두 지워진다.
 */
function AiPanel({ ai }: { ai: MemoAiStatus }) {
  const revalidator = useRevalidator();
  const submitting = useNavigation().state === "submitting";
  const attempts = useRef(0);
  const indexing = ai.enabled && ai.indexed < ai.total && ai.available;

  // 색인은 응답을 보낸 뒤 서버에서 진행되므로, 끝날 때까지 몇 초마다 진행률만 다시 가져온다(최대 약 1분).
  useEffect(() => {
    if (!indexing || attempts.current >= 20) return;
    const timer = setTimeout(() => {
      attempts.current += 1;
      revalidator.revalidate();
    }, 3000);
    return () => clearTimeout(timer);
  }, [indexing, ai.indexed, revalidator]);
  useEffect(() => {
    if (!ai.enabled) attempts.current = 0;
  }, [ai.enabled]);

  const percent = ai.total === 0 ? 100 : Math.round((ai.indexed / ai.total) * 100);

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h2 className="text-sm font-semibold text-slate-900 dark:text-white">
            의미 검색 <span className={`ml-1 text-xs font-normal ${ai.enabled ? "text-green-600 dark:text-green-400" : "text-slate-400"}`}>{ai.enabled ? "켜짐" : "꺼짐"}</span>
          </h2>
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            표현이 달라도 뜻이 비슷한 메모를 찾고, 자비스가 메모를 근거로 질문에 답해요.
            {ai.enabled
              ? " 끄면 저장된 검색용 데이터를 모두 지워요."
              : " 켜면 메모 내용이 외부 임베딩 서비스(모델: " + ai.model + ")로 전송돼요. 민감한 내용이 있다면 켜지 마세요."}
          </p>
        </div>
        <Form
          method="post"
          onSubmit={(e) => {
            if (!ai.enabled && !confirm("메모 내용이 외부 AI 임베딩 서비스로 전송돼요. 켤까요?")) e.preventDefault();
          }}
        >
          <input type="hidden" name="intent" value="ai-toggle" />
          <input type="hidden" name="enabled" value={String(!ai.enabled)} />
          <button
            type="submit"
            disabled={submitting}
            className={`shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium transition disabled:opacity-60 ${
              ai.enabled
                ? "border border-slate-300 text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                : "bg-indigo-600 text-white hover:bg-indigo-500"
            }`}
          >
            {ai.enabled ? "끄기" : "켜기"}
          </button>
        </Form>
      </div>

      {ai.enabled && (
        <div className="mt-3">
          <div className="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
            <span>
              색인 {ai.indexed}/{ai.total}
              {indexing && " · 진행 중..."}
            </span>
            {!ai.available && ai.retry_after ? <span className="text-amber-600 dark:text-amber-400">무료 사용량을 모두 써서 {humanizeSeconds(ai.retry_after)} 뒤 이어서 해요</span> : null}
          </div>
          <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
            <div className="h-full rounded-full bg-indigo-500 transition-all" style={{ width: `${percent}%` }} />
          </div>
        </div>
      )}
    </section>
  );
}
