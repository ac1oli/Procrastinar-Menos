import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'

import { ApiError } from '@/api/client'
import { errorMessage } from '@/api/queries'
import type { Memory, MemoryInput, MemoryKind } from '@/api/types'
import { Button } from '@/components/ui/button'
import { Checkbox, Field, Label, NativeSelect, Textarea } from '@/components/ui/form-controls'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/overlays'
import { kindLabel } from '@/lib/labels'

/**
 * Criação e edição de memória. A classificação de sensibilidade, o bloqueio de
 * identificadores e o limite de fixadas são decididos pelo backend; a tela só
 * mostra o resultado (inclusive os erros de validação).
 */
export function MemoryForm({
  open,
  onOpenChange,
  title,
  description,
  initial,
  showSensitive = true,
  showPinned = true,
  submitLabel,
  onSubmit,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description: string
  initial?: Partial<Pick<Memory, 'kind' | 'content' | 'pinned'>> & { sensitive?: boolean }
  showSensitive?: boolean
  showPinned?: boolean
  submitLabel: string
  onSubmit: (input: MemoryInput) => Promise<unknown>
}) {
  const [kind, setKind] = useState<MemoryKind>(initial?.kind ?? 'preferencia')
  const [content, setContent] = useState(initial?.content ?? '')
  const [sensitive, setSensitive] = useState(initial?.sensitive ?? false)
  const [pinned, setPinned] = useState(initial?.pinned ?? false)

  const save = useMutation({
    mutationFn: () =>
      onSubmit({
        kind,
        content,
        ...(showSensitive ? { sensitive } : {}),
        ...(showPinned ? { pinned } : {}),
      }),
    onSuccess: () => onOpenChange(false),
  })
  const error = save.error instanceof ApiError ? save.error : undefined

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <form
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate()
          }}
        >
          <Field label="Tipo" htmlFor="memory-kind" error={error?.field('kind')}>
            <NativeSelect id="memory-kind" value={kind} onChange={(e) => setKind(e.target.value as MemoryKind)}>
              {Object.entries(kindLabel).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <Field
            label="Memória"
            htmlFor="memory-content"
            hint={`Uma frase curta, por exemplo: "Prefere estudar no início da manhã." (${content.length}/500)`}
            error={error?.field('content')}
          >
            <Textarea id="memory-content" value={content} maxLength={500} onChange={(e) => setContent(e.target.value)} aria-invalid={!!error?.field('content')} />
          </Field>
          {showSensitive && (
            <div className="flex items-start gap-2">
              <Checkbox id="memory-sensitive" checked={sensitive} onCheckedChange={(v) => setSensitive(v === true)} />
              <Label htmlFor="memory-sensitive" className="leading-snug font-normal">
                É um dado sensível (saúde, religião, política…). Exige a autorização de memórias sensíveis.
              </Label>
            </div>
          )}
          {showPinned && (
            <div className="flex items-start gap-2">
              <Checkbox id="memory-pinned" checked={pinned} onCheckedChange={(v) => setPinned(v === true)} />
              <Label htmlFor="memory-pinned" className="leading-snug font-normal">
                Sempre considerar (fixada; até 5).
              </Label>
            </div>
          )}
          {error?.field('pinned') && <p className="text-xs text-destructive" role="alert">{error.field('pinned')}</p>}
          {save.isError && !error?.field('content') && !error?.field('pinned') && !error?.field('kind') && (
            <p role="alert" className="text-sm text-destructive">
              {errorMessage(save.error)}
            </p>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button type="submit" disabled={!content.trim() || save.isPending}>
              {save.isPending ? 'Salvando…' : submitLabel}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
