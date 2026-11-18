import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Trash2Icon } from 'lucide-react'
import type { ReactNode } from 'react'
import { toast } from 'sonner'

import { conversations, memories } from '@/api/endpoints'
import { errorMessage, keys } from '@/api/queries'
import { ConfirmDialog, useConfirm } from '@/components/ConfirmDialog'
import { Button } from '@/components/ui/button'

import { historyImpact, memoriesImpact, useDataSummary } from './impact'

/**
 * Exclusões em massa com prévia do que será afetado. Uma só implementação, usada no Histórico,
 * nas Memórias e na central "Meus dados". Os números vêm do backend (/api/me/data-summary) e as
 * consequências descritas seguem o que o banco realmente faz (chaves estrangeiras).
 */

/** Lista "será apagado / continua guardado" usada nos diálogos destrutivos. */
export function ImpactList({ removed, kept, loading }: { removed: ReactNode[]; kept?: ReactNode[]; loading?: boolean }) {
  if (loading) return <p role="status">Calculando o que será afetado…</p>
  return (
    <div className="grid gap-3 text-sm" data-testid="impact">
      <div>
        <p className="font-medium text-foreground">Será apagado definitivamente:</p>
        <ul className="mt-1 list-disc space-y-0.5 pl-5">
          {removed.map((item, i) => (
            <li key={i}>{item}</li>
          ))}
        </ul>
      </div>
      {!!kept?.length && (
        <div>
          <p className="font-medium text-foreground">Continua guardado:</p>
          <ul className="mt-1 list-disc space-y-0.5 pl-5">
            {kept.map((item, i) => (
              <li key={i}>{item}</li>
            ))}
          </ul>
        </div>
      )}
      <p>Esta ação não pode ser desfeita.</p>
    </div>
  )
}

function useAfterDeletion() {
  const queryClient = useQueryClient()
  return () => {
    for (const key of [['conversations'], ['memories'], keys.suggestions, keys.dataSummary]) queryClient.invalidateQueries({ queryKey: key })
  }
}

export function ClearHistoryAction({ variant = 'outline' }: { variant?: 'outline' | 'ghost' | 'destructive' }) {
  const confirm = useConfirm()
  const summary = useDataSummary(confirm.isOpen)
  const after = useAfterDeletion()
  const clear = useMutation({
    mutationFn: conversations.clear,
    onSuccess: (r) => {
      toast.success(`${r.deleted_conversations} conversa(s) excluída(s).`)
      confirm.close()
      after()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const impact = summary.data && historyImpact(summary.data)

  return (
    <>
      <Button variant={variant} onClick={() => confirm.open(true)}>
        <Trash2Icon /> Apagar todo o histórico
      </Button>
      <ConfirmDialog
        open={confirm.isOpen}
        onOpenChange={(o) => !o && confirm.close()}
        title="Apagar todo o histórico?"
        description={<ImpactList loading={!impact} removed={impact?.removed ?? []} kept={impact?.kept} />}
        confirmLabel="Apagar tudo"
        pending={clear.isPending}
        confirmDisabled={!impact}
        onConfirm={() => clear.mutate()}
      />
    </>
  )
}

export function ClearMemoriesAction({ variant = 'ghost' }: { variant?: 'outline' | 'ghost' | 'destructive' }) {
  const confirm = useConfirm()
  const summary = useDataSummary(confirm.isOpen)
  const after = useAfterDeletion()
  const clear = useMutation({
    mutationFn: memories.clear,
    onSuccess: (r) => {
      confirm.close()
      toast.success(`${r.deleted_memories} memória(s) excluída(s).`)
      after()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const impact = summary.data && memoriesImpact(summary.data)

  return (
    <>
      <Button variant={variant} onClick={() => confirm.open(true)}>
        <Trash2Icon /> Excluir todas as memórias
      </Button>
      <ConfirmDialog
        open={confirm.isOpen}
        onOpenChange={(o) => !o && confirm.close()}
        title="Excluir todas as memórias?"
        description={<ImpactList loading={!impact} removed={impact?.removed ?? []} kept={impact?.kept} />}
        confirmLabel="Excluir todas"
        pending={clear.isPending}
        confirmDisabled={!impact}
        onConfirm={() => clear.mutate()}
      />
    </>
  )
}

