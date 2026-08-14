import fs from 'node:fs/promises'
import path from 'node:path'

export class MassUploadClient {
  constructor(config, fetchImpl = fetch) {
    this.config = config
    this.fetch = fetchImpl
  }

  async request(pathname, options = {}) {
    const response = await this.fetch(`${this.config.apiBaseUrl}${pathname}`, {
      ...options,
      headers: { Authorization: `Bearer ${this.config.token}`, Accept: 'application/json', ...(options.headers || {}) }
    })
    if (!response.ok) throw new Error(`Mass upload API returned HTTP ${response.status}.`)
    return response
  }

  async heartbeat() {
    await this.request('/internal/shopee-gita-mass-upload/heartbeat', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ worker_name: this.config.workerName }) })
  }

  async claim() {
    const response = await this.request('/internal/shopee-gita-mass-upload/claim', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ worker_name: this.config.workerName }) })
    return (await response.json()).data || null
  }

  async event(jobId, fileId, payload) {
    const { claimToken, ...eventPayload } = payload
    const response = await this.request(`/internal/shopee-gita-mass-upload/jobs/${jobId}/files/${fileId}/event`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Gitashop-Mass-Upload-Claim': claimToken }, body: JSON.stringify(eventPayload) })
    return (await response.json()).data
  }

  async renew(jobId, claimToken) {
    await this.request(`/internal/shopee-gita-mass-upload/jobs/${jobId}/renew`, { method: 'POST', headers: { 'X-Gitashop-Mass-Upload-Claim': claimToken } })
  }

  async reconcile(jobId, fileId, payload) {
    const response = await this.request(`/internal/shopee-gita-mass-upload/jobs/${jobId}/files/${fileId}/reconcile`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    return (await response.json()).data
  }

  async terminal(jobId, payload) {
    await this.request(`/internal/shopee-gita-mass-upload/jobs/${jobId}/terminal`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
  }

  async download(jobId, file, claimToken, directory) {
    await fs.mkdir(directory, { recursive: true })
    const response = await this.request(`/internal/shopee-gita-mass-upload/jobs/${jobId}/files/${file.id}/download`, { headers: { 'X-Gitashop-Mass-Upload-Claim': claimToken } })
    const target = path.join(directory, file.filename)
    await fs.writeFile(target, Buffer.from(await response.arrayBuffer()))
    return target
  }
}
