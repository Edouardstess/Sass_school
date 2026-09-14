# ---- build ----------------------------------------------------------------
FROM mcr.microsoft.com/dotnet/sdk:10.0-alpine AS build
WORKDIR /src

# Copy the manifests first: this layer only changes when dependencies do, so
# an ordinary code change reuses the restore cache.
COPY Directory.Build.props Directory.Packages.props global.json ./
COPY src/STED.RestaurantOS.Shared/*.csproj src/STED.RestaurantOS.Shared/
COPY src/STED.RestaurantOS.Domain/*.csproj src/STED.RestaurantOS.Domain/
COPY src/STED.RestaurantOS.Application/*.csproj src/STED.RestaurantOS.Application/
COPY src/STED.RestaurantOS.Infrastructure/*.csproj src/STED.RestaurantOS.Infrastructure/
COPY src/STED.RestaurantOS.API/*.csproj src/STED.RestaurantOS.API/
RUN dotnet restore src/STED.RestaurantOS.API/STED.RestaurantOS.API.csproj

COPY . .
RUN dotnet publish src/STED.RestaurantOS.API/STED.RestaurantOS.API.csproj \
    -c Release -o /app --no-restore

# ---- runtime --------------------------------------------------------------
FROM mcr.microsoft.com/dotnet/aspnet:10.0-alpine AS runtime
WORKDIR /app

# Non-root. A container that never needs to write outside /tmp should not run
# as root, and this one does not.
RUN addgroup -S sted && adduser -S sted -G sted
COPY --from=build --chown=sted:sted /app .
USER sted

ENV ASPNETCORE_URLS=http://+:8080 \
    ASPNETCORE_ENVIRONMENT=Production \
    DOTNET_SYSTEM_GLOBALIZATION_INVARIANT=true

EXPOSE 8080

# Liveness only: readiness is the orchestrator's business and checks the
# database, which must not be able to make Docker kill a healthy process.
HEALTHCHECK --interval=30s --timeout=3s --start-period=20s --retries=3 \
    CMD wget -qO- http://localhost:8080/health/live || exit 1

ENTRYPOINT ["dotnet", "STED.RestaurantOS.API.dll"]
