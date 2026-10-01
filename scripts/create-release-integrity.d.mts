export type ReleaseIntegrityMetadata = {
  releaseContractVersion: number;
  publicationId: string;
  sequence: number;
  sourceRevision: string;
  packageSha256: string;
  manifestSha256: string;
  releaseFilesSha256: string;
  media: Array<{ publicPath: string; sha256: string; size: number; type: string }>;
};

export function createReleaseIntegrity(outputDirectory: string, publicationPath: string): Promise<string>;
