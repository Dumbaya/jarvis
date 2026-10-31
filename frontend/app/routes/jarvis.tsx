import { useEffect, useRef, useState } from "react";

import { useRouteLoaderData } from "react-router";

import { api, ApiError, chatStorageKey, type User } from "~/lib/api";
import type { Route } from "./+types/jarvis";

export const meta: Route.MetaFunction = () => [{ title: "자비스 | Jarvis" }];

type PendingAction = { id: number; tool: string; description: string; arguments: Record<string, unknown> };
type ChatResponse = {
  reply: string;
  tool_calls: { name: string; status: string }[];
  pending_action?: PendingAction;
  fallback?: boolean;
};
type Message = {
  role: "user" | "assistant";
  content: string;
  notice?: string;
  pending?: PendingAction;
  pendingState?: "idle" | "running" | "done" | "cancelled" | "failed";
};

const HISTORY_LIMIT = 10;
const STORED_LIMIT = 50;

function load(key: string): Message[] {
  try {
    const parsed = JSON.parse(localStorage.getItem(key) ?? "[]");
    if (!Array.isArray(parsed)) return [];
    // 처리 중에 화면을 벗어났다면 결과를 알 수 없으므로 다시 요청하도록 안내한다.
    return parsed.map((m: Message) =>
      m.pendingState === "running"
        ? { ...m, pendingState: "failed", notice: "처리 결과를 확인하지 못했어요. 메모 목록을 확인해 주세요." }
        : m,
    );
  } catch {
    return [];
  }
}

export default function Jarvis() {
  const user = useRouteLoaderData("routes/app-layout") as User;
  const storageKey = chatStorageKey(user.id);
  const [messages, setMessages] = useState<Message[]>(() => load(storageKey));
  const [input, setInput] = useState("");
  const [busy, setBusy] = useState(false);
  const bottomRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages, busy]);

  // 대화가 바뀔 때마다 브라우저에 저장한다. 저장소가 막혀 있어도 대화 자체는 계속 된다.
  useEffect(() => {
    try {
      localStorage.setItem(storageKey, JSON.stringify(messages.slice(-STORED_LIMIT)));
    } catch {
      // 용량 초과나 시크릿 모드에서는 저장을 건너뛴다.
    }
  }, [messages, storageKey]);

  function reset() {
    if (messages.length && !confirm("대화를 모두 지울까요?")) return;
    setMessages([]);
  }

  const add = (m: Message) => setMessages((prev) => [...prev, m]);
  const patchPending = (id: number, state: Message["pendingState"], content?: string) =>
    setMessages((prev) =>
      prev.map((m) =>
        m.pending?.id === id ? { ...m, pendingState: state, ...(content ? { notice: content } : {}) } : m,
      ),
    );

  async function send(e: React.FormEvent) {
    e.preventDefault();
    const text = input.trim();
    if (!text || busy) return;

    const next: Message[] = [...messages, { role: "user", content: text }];
    setMessages(next);
    setInput("");
    setBusy(true);

    try {
      // 서버는 대화 내용을 저장하지 않으므로 최근 대화만 매번 보낸다. 확인 대기 카드 같은 UI 상태는 제외한다.
      const history = next.slice(-HISTORY_LIMIT).map(({ role, content }) => ({ role, content }));
      const res = await api<ChatResponse>("/jarvis/chat", { method: "POST", body: { messages: history } });
      add({
        role: "assistant",
        content: res.reply,
        pending: res.pending_action,
        pendingState: res.pending_action ? "idle" : undefined,
      });
    } catch (err) {
      add({ role: "assistant", content: err instanceof ApiError ? err.message : "문제가 생겼어요. 다시 시도해 주세요." });
    } finally {
      setBusy(false);
    }
  }

  async function decide(id: number, action: "confirm" | "cancel") {
    patchPending(id, "running");
    try {
      await api(`/jarvis/actions/${id}/${action}`, { method: "POST" });
      patchPending(id, action === "confirm" ? "done" : "cancelled");
    } catch (err) {
      const message = err instanceof ApiError ? err.message : "처리하지 못했어요.";
      // 409는 이미 처리되었거나 만료된 경우라 다시 누를 수 없게 막는다.
      patchPending(id, "failed", message);
    }
  }

  return (
    <div className="flex h-[calc(100vh-9rem)] flex-col">
      <div className="mb-4 flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900 dark:text-white">자비스</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            말로 메모를 찾고 저장하세요. 저장처럼 데이터를 바꾸는 작업은 확인 후에 실행돼요.
          </p>
        </div>
        <button
          onClick={reset}
          disabled={busy || messages.length === 0}
          className="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-600 transition hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
        >
          새 대화
        </button>
      </div>

      <div className="flex-1 space-y-4 overflow-y-auto rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        {messages.length === 0 && (
          <div className="py-10 text-center text-sm text-slate-500 dark:text-slate-400">
            <p className="font-medium text-slate-700 dark:text-slate-200">무엇을 도와드릴까요?</p>
            <p className="mt-2">예: &quot;nginx 관련 메모 찾아줘&quot;, &quot;저장: 내일 회의 준비&quot;</p>
          </div>
        )}

        {messages.map((m, i) => (
          <div key={i} className={m.role === "user" ? "flex justify-end" : "flex justify-start"}>
            <div
              className={`max-w-[85%] whitespace-pre-wrap break-words rounded-2xl px-4 py-2.5 text-sm ${
                m.role === "user"
                  ? "bg-indigo-600 text-white"
                  : "bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100"
              }`}
            >
              {m.content}

              {m.pending && (
                <div className="mt-3 rounded-xl border border-slate-300 bg-white p-3 text-slate-700 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200">
                  <p className="text-xs font-medium text-slate-500">{m.pending.tool}</p>
                  <p className="mt-1">{m.pending.description}</p>
                  <pre className="mt-2 overflow-x-auto rounded-lg bg-slate-50 p-2 text-xs dark:bg-slate-950">
                    {JSON.stringify(m.pending.arguments, null, 2)}
                  </pre>

                  {m.pendingState === "idle" || m.pendingState === "running" ? (
                    <div className="mt-3 flex gap-2">
                      <button
                        onClick={() => decide(m.pending!.id, "confirm")}
                        disabled={m.pendingState === "running"}
                        className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-indigo-500 disabled:opacity-60"
                      >
                        {m.pendingState === "running" ? "처리 중..." : "실행"}
                      </button>
                      <button
                        onClick={() => decide(m.pending!.id, "cancel")}
                        disabled={m.pendingState === "running"}
                        className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-600 transition hover:bg-slate-100 disabled:opacity-60 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
                      >
                        취소
                      </button>
                    </div>
                  ) : (
                    <p
                      className={`mt-3 text-xs font-medium ${
                        m.pendingState === "done"
                          ? "text-green-600 dark:text-green-400"
                          : m.pendingState === "failed"
                            ? "text-red-600 dark:text-red-400"
                            : "text-slate-500"
                      }`}
                    >
                      {m.pendingState === "done" && "✓ 실행했어요"}
                      {m.pendingState === "cancelled" && "취소했어요"}
                      {m.pendingState === "failed" && (m.notice ?? "처리하지 못했어요")}
                    </p>
                  )}
                </div>
              )}
            </div>
          </div>
        ))}

        {busy && (
          <div className="flex justify-start">
            <div className="rounded-2xl bg-slate-100 px-4 py-2.5 text-sm text-slate-500 dark:bg-slate-800">생각 중...</div>
          </div>
        )}
        <div ref={bottomRef} />
      </div>

      <form onSubmit={send} className="mt-4 flex gap-2">
        <input
          value={input}
          onChange={(e) => setInput(e.target.value)}
          placeholder="메시지를 입력하세요"
          maxLength={4000}
          autoFocus
          className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
        />
        <button
          type="submit"
          disabled={busy || !input.trim()}
          className="shrink-0 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
        >
          보내기
        </button>
      </form>
    </div>
  );
}
